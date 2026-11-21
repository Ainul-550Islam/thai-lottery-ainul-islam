
GRANT SELECT ON performance_schema.data_locks TO 'thailotto_ci'@'%';

GRANT SELECT ON performance_schema.data_lock_waits TO 'thailotto_ci'@'%';

GRANT SELECT ON performance_schema.threads TO 'thailotto_ci'@'%';

GRANT PROCESS ON *.* TO 'thailotto_ci'@'%';

CREATE DATABASE settlement_worker_destructive_test;

CREATE USER 'settlement_worker_test'@'%' IDENTIFIED BY 'ci_settlement_worker_password';

GRANT ALL PRIVILEGES ON settlement_worker_destructive_test.* TO 'settlement_worker_test'@'%';

GRANT SELECT ON thai_lottery_test.* TO 'settlement_worker_test'@'%';

CREATE DATABASE thai_lottery_conc;

CREATE USER 'concurrency_test'@'%' IDENTIFIED BY 'ci_concurrency_password';

GRANT ALL PRIVILEGES ON thai_lottery_conc.* TO 'concurrency_test'@'%';

FLUSH PRIVILEGES;

