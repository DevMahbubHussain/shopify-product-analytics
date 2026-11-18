SELECT 'CREATE DATABASE shopify_analytics_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'shopify_analytics_test')\gexec
