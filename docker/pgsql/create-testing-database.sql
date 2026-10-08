SELECT 'CREATE DATABASE sixth_sense_testing OWNER sixth_sense'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sixth_sense_testing')\gexec
