import pandas as pd
from sqlalchemy import create_engine, text
from db_config_loader import get_mysql_config
import math
import pymysql


def fetch_pairs(yaml_path, asin=None, sales_channel=None, iwasku=None):
    engine = None
    try:
        mysql_config = get_mysql_config(yaml_path)
        db_url = (
            f"mysql+mysqlconnector://{mysql_config['user']}:{mysql_config['password']}@"
            f"{mysql_config['host']}:{mysql_config['port']}/{mysql_config['database']}"
        )
        engine = create_engine(db_url)
        base_query = "SELECT DISTINCT asin, sales_channel FROM iwa_amazon_daily_sales_summary WHERE data_source = 1"
        if asin:
            base_query += f" AND asin = '{asin}'"
        if sales_channel:
            base_query += f" AND sales_channel = '{sales_channel}'"
        if iwasku:
            base_query += f" AND iwasku = '{iwasku}'"
        df = pd.read_sql(base_query, engine)
        return df
    except Exception as e:
        print(f"Error fetching ASIN/Sales Channel pairs: {e}")
        return pd.DataFrame()
    finally:
        if engine:
            engine.dispose()


def fetch_groups(yaml_path):
    engine = None
    try:
        mysql_config = get_mysql_config(yaml_path)
        db_url = (
            f"mysql+mysqlconnector://{mysql_config['user']}:{mysql_config['password']}@"
            f"{mysql_config['host']}:{mysql_config['port']}/{mysql_config['database']}"
        )
        engine = create_engine(db_url)
        query = "SELECT DISTINCT LEFT(iwasku, 2) AS group_id FROM iwa_amazon_daily_sales_summary WHERE data_source = 1 ORDER BY group_id"
        df = pd.read_sql(query, engine)
        return df['group_id'].tolist()
    except Exception as e:
        print(f"Error fetching groups: {e}")
        return []
    finally:
        if engine:
            engine.dispose()


def fetch_group_data(group_id, yaml_path):
    engine = None
    try:
        mysql_config = get_mysql_config(yaml_path)
        db_url = (
            f"mysql+mysqlconnector://{mysql_config['user']}:{mysql_config['password']}@"
            f"{mysql_config['host']}:{mysql_config['port']}/{mysql_config['database']}"
        )
        engine = create_engine(db_url)
        query = (
            "SELECT sale_date AS ds, SUM(total_quantity) AS y "
            "FROM iwa_amazon_daily_sales_summary "
            "WHERE LEFT(iwasku, 2) = %s AND data_source = 1 "
            "GROUP BY sale_date "
            "ORDER BY sale_date ASC"
        )
        df = pd.read_sql(query, engine, params=(group_id,))
        if not df.empty:
            # Remove the latest date if data might be incomplete
            latest_date = df['ds'].max()
            df = df[df['ds'] != latest_date]
        print(df)
        return df
    except Exception as e:
        print(f"Error fetching data for group {group_id}: {e}")
        return pd.DataFrame()
    finally:
        if engine:
            engine.dispose()



def fetch_data(asin, sales_channel, yaml_path):
    engine = None
    try:
        mysql_config = get_mysql_config(yaml_path)
        db_url = f"mysql+mysqlconnector://{mysql_config['user']}:{mysql_config['password']}@{mysql_config['host']}:{mysql_config['port']}/{mysql_config['database']}"
        engine = create_engine(db_url)
        query = "SELECT sale_date AS ds, total_quantity AS y FROM iwa_amazon_daily_sales_summary WHERE asin = %s AND sales_channel = %s AND data_source = 1 ORDER BY sale_date ASC;"
        df = pd.read_sql(query, engine, params=(asin, sales_channel))
        if not df.empty:
            latest_date = df['ds'].max()
            df = df[df['ds'] != latest_date]
        return df
    except Exception as e:
        print(f"Error fetching data for ASIN {asin} and Sales Channel {sales_channel}: {e}")
        return pd.DataFrame()
    finally:
        if engine:
            engine.dispose()




def insert_forecast_data(forecast_data, asin, sales_channel, yaml_path):
    required_columns = {'ds', 'yhat'}
    if not required_columns.issubset(forecast_data.columns):
        raise ValueError(f"Forecast data must contain columns: {required_columns}")
    connection = None
    try:
        mysql_config = get_mysql_config(yaml_path)
        connection = pymysql.connect(
            host=mysql_config['host'],
            user=mysql_config['user'],
            password=mysql_config['password'],
            database=mysql_config['database'],
            port=mysql_config.get('port', 3306),
            cursorclass=pymysql.cursors.DictCursor,
        )
        iwasku_query = "SELECT COALESCE((SELECT regvalue FROM iwa_registry WHERE regtype = 'asin-to-iwasku' AND regkey = %s), %s) AS iwasku"
        insert_query = """
        INSERT INTO iwa_amazon_daily_sales_summary (asin, sales_channel, iwasku, sale_date, total_quantity, data_source)
        VALUES (%s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            total_quantity = VALUES(total_quantity)
        """
        with connection.cursor() as cursor:
            cursor.execute(iwasku_query, (asin, asin))
            iwasku_result = cursor.fetchone()
            iwasku = iwasku_result['iwasku'] if iwasku_result else asin
            forecast_data['asin'] = asin
            forecast_data['sales_channel'] = sales_channel
            forecast_data['iwasku'] = iwasku
            forecast_data['data_source'] = 0  # 0 indicates forecasted data
            forecast_data = forecast_data.rename(columns={'ds': 'sale_date', 'yhat': 'total_quantity'})
            forecast_data['total_quantity'] = forecast_data['total_quantity'].apply(lambda x: max(x, 0))
            forecast_data['sale_date'] = forecast_data['sale_date'].dt.strftime('%Y-%m-%d')  # Ensure DATE format
            rows_to_insert = [
                (
                    row.asin,
                    row.sales_channel,
                    row.iwasku,
                    row.sale_date,
                    row.total_quantity,
                    row.data_source
                )
                for row in forecast_data.itertuples(index=False)
            ]
            print("Inserting data into the database...")
            for row in rows_to_insert:
                cursor.execute(insert_query, row)
            print("Committing changes...")
            connection.commit()
    except Exception as e:
        print(f"Error inserting/updating forecast data: {e}")
        raise
    finally:
        if connection:
            connection.close()


def delete_forecast_data(asin, sales_channel, yaml_path):
    conn = None
    try:
        mysql_config = get_mysql_config(yaml_path)
        connection = pymysql.connect(
            host=mysql_config['host'],
            user=mysql_config['user'],
            password=mysql_config['password'],
            database=mysql_config['database'],
            port=mysql_config.get('port', 3306),
            cursorclass=pymysql.cursors.DictCursor,
        )
        cursor = connection.cursor()
        query = "DELETE FROM iwa_amazon_daily_sales_summary WHERE asin = %s AND sales_channel = %s AND data_source = 0"
        cursor.execute(query, (asin, sales_channel))
        connection.commit()
    except pymysql.MySQLError as e:
        print(f"Error deleting forecast data for ASIN {asin}, Sales Channel {sales_channel}: {e}")
    finally:
        if connection:
            connection.close()
