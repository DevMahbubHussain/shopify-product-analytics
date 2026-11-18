SELECT 'CREATE DATABASE shopify_sessions'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'shopify_sessions')\gexec
