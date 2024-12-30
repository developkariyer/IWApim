<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OzonTaskProductFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $taskId = $options['task_id'];
        $parentProductId = $options['parent_product_id'];
        $children = $options['children'];
        $selectedChildren = $options['selected_children'];

        // Hidden fields
        $builder
            ->add('taskId', HiddenType::class, [
                'data' => $taskId,
            ])
            ->add('productId', HiddenType::class, [
                'data' => $parentProductId,
            ]);

        // Dynamically add size and color groups
        foreach ($children as $colorGroup) {
            foreach ($colorGroup as $child) {
                $builder->add("selectedChildren[{$child->getId()}]", ChoiceType::class, [
                    'choices' => array_merge(
                        [
                            'Listeleme' => -1,
                            'PIM Bilgilerini Kullan' => 0,
                        ],
                        array_combine(
                            array_map(fn($item) => $item->getKey(), $child->getListingItems()),
                            array_map(fn($item) => $item->getId(), $child->getListingItems())
                        )
                    ),
                    'label' => $child->getKey(),
                    'data' => $selectedChildren[$child->getId()] ?? -1,
                    'attr' => [
                        'class' => 'form-select',
                        'id' => "childSelect{$child->getId()}",
                    ],
                    'required' => false,
                ]);
            }
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'Güncelle',
            'attr' => [
                'class' => 'btn btn-primary mt-3',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // Or a DTO if you want to bind data to an object
            'task_id' => null,
            'parent_product_id' => null,
            'children' => [],
            'selected_children' => [],
        ]);
    }
}