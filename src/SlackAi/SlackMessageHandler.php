<?php

namespace App\SlackAi;

use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Psr\Log\LoggerInterface;
use OpenAI;

class SlackMessageHandler implements MessageHandlerInterface
{
    private LoggerInterface $logger;
    private string $botUserId;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->botUserId = 'U080PEA1HAR'; 
    }

    public function __invoke(SlackMessage $message): void
    {
        try {
            // Process the message content
            $responseText = $this->processMessage(
                trim(
                    preg_replace(
                        '/<@' . preg_quote($this->botUserId, '/') . '>/', 
                        '', 
                        $message->getText()
                    )
                ),
                $message->getUser()
            );

            // Prepare payload for Slack response
            $payload = [
                'text' => $responseText,
                'response_type' => 'in_channel', // Makes the response visible to everyone
                'replace_original' => false,    // Avoids overwriting the original message
            ];

            // Add thread_ts if available
            if ($message->getThreadTs()) {
                $payload['thread_ts'] = $message->getThreadTs();
            }

            // Send the response back to Slack using the Webhook URL
            $this->sendResponseToSlack($payload);

            $this->logger->info('SlackMessage processed successfully', [
                'text' => $message->getText(),
                'thread_ts' => $message->getThreadTs(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process SlackMessage', [
                'message' => $e->getMessage(),
                'text' => $message->getText(),
            ]);
        }
    }

    private function processMessage(string $text, string $user): string
    {
        $client = OpenAI::Client($_ENV['OPENAI_SECRET'] ?? null);
        if (!$client) {
            throw new \RuntimeException('OPENAI_API_KEY is not defined in environment variables or Client init failed.');
        }
        error_log("OpenAI client initialized successfully.");
        $runResponse = $client->threads()->createAndRun([
            'assistant_id' => $_ENV['OPENAI_ASSISTANT_ID'] ?? null,
            'thread' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $text,
                    ],
                ],
            ],
        ]);
        $threadId = $runResponse->threadId;
        error_log("Assistant run created successfully: {$runResponse->id}");

        $responseContent = "";
        $loopCounter = 0;
        while (true) {
            $running = false;
            while (!in_array($runResponse->status, ['requires_action', 'completed', 'failed', 'cancelled', 'expired'])) {
                error_log("Assistant run status: {$runResponse->status}");
                sleep(1);
                $runResponse = $client->threads()->runs()->retrieve($threadId, $runResponse->id);
            }
            error_log("Assistant run status: {$runResponse->status}");
            $tool_outputs = [];
            $runStepList = $client->threads()->runs()->steps()->list($threadId, $runResponse->id);
            error_log("Assistant run steps fetched successfully. ".count($runStepList->data)." steps found.");
            foreach ($runStepList->data as $step) {
                if ($step->type === 'message_creation') {
                    error_log("Assistant response step found: {$step->stepDetails->messageCreation->messageId}");
                    $messageId = $step->stepDetails->messageCreation->messageId;
                    $assistantMessage = $client->threads()->messages()->retrieve($threadId, $messageId);
                    foreach ($assistantMessage->content ?? [] as $content) {
                        if ($content->type === 'text') {
                            $responseContent .= "\n".$content->text->value;
                            error_log("Assistant response message: {$content->text->value}");
                        }
                    }
                } elseif ($step->type === 'tool_calls') {
                    error_log("Function call detected.");
                    $logarray = $step->stepDetails->toArray();
                    error_log(json_encode($logarray));
                    $toolCalls = $step->stepDetails->toolCalls ?? [];
                    foreach ($toolCalls as $toolCall) {
                        $callId = $toolCall->id;
                        if ($toolCall->type === 'function') {
                            if ($toolCall->function->output !== null) {
                                continue;
                            }
                            $functionName = $toolCall->function->name;
                            $arguments = $toolCall->function->arguments;
                            error_log("Function Call ID: {$callId}");
                            error_log("Function Name: {$functionName}");
                            error_log("Function Arguments: " . json_encode($arguments));
                            $functionResult = $this->executeFunction($functionName, $arguments);
                            error_log("Function Result: {$functionResult}");
                            $tool_outputs[] = [
                                'tool_call_id' => $callId,
                                'output' => $functionResult,
                            ];
                            $running = true;
                        }
                    }
                }
            }
            if ($running) {
                $runResponse = $client->threads()->runs()->retrieve($threadId, $runResponse->id);
                while (!in_array($runResponse->status, ['requires_action', 'completed', 'failed', 'cancelled', 'expired'])) {
                    error_log("Assistant run status: {$runResponse->status}");
                    sleep(1);
                    $runResponse = $client->threads()->runs()->retrieve($threadId, $runResponse->id);
                }
                error_log("Assistant run status: {$runResponse->status}");
                error_log(json_encode($tool_outputs));
                $submitResponse = $client->threads()->runs()->submitToolOutputs($threadId, $runResponse->id, ['tool_outputs' => $tool_outputs]);
                $tool_outputs = [];
                error_log("Submit Response Status: {$submitResponse->status}");
                error_log("Tool outputs submitted successfully.");
            } else {
                error_log("Assistant run completed successfully.");
                if (!empty($responseContent) || $loopCounter > 20) {
                    break;
                } else {
                    sleep(1);
                    $loopCounter++;
                }
            }
        } 
        $client->threads()->delete($threadId);
        error_log("Final Response content: {$responseContent}");
        return $responseContent ?? "Hüstın bir sorun var...";
    }

    private function executeFunction(string $functionName, string $arguments): string
    {
        return match($functionName) {
            'run_mysql_query' => $this->runMysqlQuery(json_decode($arguments, true)),
            default => "Function not found: {$functionName}",
        };
    }

    private function runMysqlQuery($arguments)
    {
        $db = \Pimcore\Db::get();
        $query = trim($arguments['query']);
        $params = $arguments['parameters'] ?? [];
    
        // Ensure the query starts with SELECT
        if (!preg_match('/^SELECT\s/i', $query)) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed.');
        }
    
        // Check if the query has a LIMIT clause
        if (!preg_match('/\bLIMIT\b/i', $query)) {
            // Append LIMIT 10 if not present
            $query = rtrim($query, ';') . ' LIMIT 10';
        }
    
        // Execute the query
        $result = $db->executeQuery($query, $params)->fetchAllAssociative();
    
        return json_encode($result);
    }
    
    private function sendResponseToSlack(array $payload): void
    {
        $httpClient = HttpClient::create();

        // Use predefined Webhook URL from environment variable
        $webhookUrl = $_ENV['SLACK_AI_WEBHOOK_URL'] ?? null;

        if (!$webhookUrl) {
            throw new \RuntimeException('SLACK_AI_WEBHOOK_URL is not defined in environment variables.');
        }

        // Send POST request to Slack Webhook URL
        $response = $httpClient->request('POST', $webhookUrl, [
            'json' => $payload,
        ]);

        // Log HTTP response for debugging
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to send response to Slack. Status Code: ' . $response->getStatusCode());
        }
    }
}
