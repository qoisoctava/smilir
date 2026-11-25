-- smilir schema — MySQL 5.7+ / MariaDB 10.3+
-- Usage: mysql -u USER -p DBNAME < schema.sql
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- venvs
CREATE TABLE IF NOT EXISTS pn_venv (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(64)  NOT NULL UNIQUE,
  path              VARCHAR(255) NOT NULL,
  python_version    VARCHAR(32)  NULL,
  pip_version       VARCHAR(32)  NULL,
  is_default        TINYINT(1)   NOT NULL DEFAULT 0,
  status            ENUM('healthy','outdated','error','missing') NOT NULL DEFAULT 'healthy',
  last_checked_at   DATETIME     NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pn_venv_package (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venv_id        INT UNSIGNED NOT NULL,
  name           VARCHAR(128) NOT NULL,
  version        VARCHAR(64)  NOT NULL,
  latest_version VARCHAR(64)  NULL,
  updated_at     DATETIME     NOT NULL,
  UNIQUE KEY uq_venv_pkg (venv_id, name),
  CONSTRAINT fk_pkg_venv FOREIGN KEY (venv_id) REFERENCES pn_venv(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------- dags
CREATE TABLE IF NOT EXISTS pn_dag (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(128) NOT NULL UNIQUE,
  schedule_type    ENUM('cron','interval') NOT NULL DEFAULT 'cron',
  schedule         VARCHAR(128) NOT NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  is_favorite      TINYINT(1)   NOT NULL DEFAULT 0,
  max_concurrency  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  default_venv_id  INT UNSIGNED NULL,
  source_path      VARCHAR(255) NOT NULL,
  source_hash      CHAR(64)     NULL,
  source_mtime     DATETIME     NULL,
  loader           ENUM('py','php','yaml') NOT NULL DEFAULT 'py',
  next_run_at      DATETIME     NULL,
  last_run_at      DATETIME     NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  KEY idx_dag_due (is_active, next_run_at),
  CONSTRAINT fk_dag_venv FOREIGN KEY (default_venv_id) REFERENCES pn_venv(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pn_task (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dag_id           INT UNSIGNED NOT NULL,
  name             VARCHAR(128) NOT NULL,
  runner           ENUM('shell','http','php','python') NOT NULL DEFAULT 'python',
  func             VARCHAR(255) NULL,
  command          TEXT         NOT NULL,
  venv_id          INT UNSIGNED NULL,
  depends_on       VARCHAR(255) NOT NULL DEFAULT '',
  retries          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  retry_delay      SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  timeout          INT UNSIGNED NOT NULL DEFAULT 0,
  email_on_failure TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_dag_task (dag_id, name),
  CONSTRAINT fk_task_dag  FOREIGN KEY (dag_id)  REFERENCES pn_dag(id)  ON DELETE CASCADE,
  CONSTRAINT fk_task_venv FOREIGN KEY (venv_id) REFERENCES pn_venv(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------- runs
CREATE TABLE IF NOT EXISTS pn_dag_run (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dag_id      INT UNSIGNED NOT NULL,
  run_id      VARCHAR(64)  NOT NULL,
  state       ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  started_at  DATETIME     NOT NULL,
  finished_at DATETIME     NULL,
  UNIQUE KEY uq_dag_run (dag_id, run_id),
  CONSTRAINT fk_dagrun_dag FOREIGN KEY (dag_id) REFERENCES pn_dag(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pn_task_run (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dag_run_id      INT UNSIGNED NOT NULL,
  task_id         INT UNSIGNED NOT NULL,
  attempt         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  state           ENUM('queued','running','success','failed',
                       'upstream_failed','skipped','timed_out')
                  NOT NULL DEFAULT 'queued',
  started_at      DATETIME     NULL,
  finished_at     DATETIME     NULL,
  next_attempt_at DATETIME     NULL,
  pid             INT          NULL,
  exit_code       INT          NULL,
  log_path        VARCHAR(255) NULL,
  result_json     TEXT         NULL,
  error           TEXT         NULL,
  alerted         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  KEY idx_dagrun_state (dag_run_id, state),
  CONSTRAINT fk_taskrun_dagrun FOREIGN KEY (dag_run_id) REFERENCES pn_dag_run(id) ON DELETE CASCADE,
  CONSTRAINT fk_taskrun_task   FOREIGN KEY (task_id)    REFERENCES pn_task(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- audit
CREATE TABLE IF NOT EXISTS pn_audit (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME  NOT NULL,
  actor      VARCHAR(64)  NOT NULL,
  action     VARCHAR(64)  NOT NULL,
  object     VARCHAR(255) NOT NULL,
  ip         VARCHAR(45)  NULL,
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS pn_user (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL UNIQUE,
  email         VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------- settings
CREATE TABLE IF NOT EXISTS pn_setting (
  k          VARCHAR(64) NOT NULL PRIMARY KEY,
  v          TEXT        NOT NULL,
  updated_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
