.PHONY: unit e2e ui ui-actions pass-change check tick clean-test dist \
        docker-up docker-down docker-logs docker-smoke

# Archive/root folder name. `pipnix` = deployment profile (extracts to ~/pipnix);
# `smilir` = clean install profile. See docs/BRAND.md.
PREFIX ?= pipnix

unit:            ## cron/logger/updater/env unit tests (no DB, no network)
	php tests/php/cron_test.php
	php tests/php/updater_test.php
	php tests/php/env_test.php

check: unit      ## php syntax + unit tests
	@find app -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
	@echo "php lint ok"

e2e:             ## full integration test (needs dev MySQL on PN_MYSQL_PORT)
	bash tests/e2e.sh

ui:              ## web console smoke test (auth + every page)
	bash tests/ui_smoke.sh

ui-actions:      ## web console write-action tests (editor, import, trigger, venv, users)
	bash tests/ui_actions.sh

pass-change:     ## forced password change on weak install passwords
	bash tests/pass_change.sh

tick:            ## one scheduler tick
	php app/scheduler.php

clean-test:      ## remove e2e leftovers
	rm -f app/config.php dags/e2e_flow.py dags/e2e_fail.py jobs/e2e_demo.py
	rm -rf dags/__pycache__ jobs/__pycache__

dist:            ## release zip: dist/$(PREFIX)-v<version>.zip (PREFIX=smilir for utama)
	@mkdir -p dist && \
	V=$$(grep -o "PN_VERSION', '[^']*" app/bootstrap.php | cut -d"'" -f3) && \
	git archive --format=zip --prefix="$(PREFIX)/" -o "dist/$(PREFIX)-v$$V.zip" HEAD && \
	echo "built dist/$(PREFIX)-v$$V.zip"

docker-up:       ## boot the Docker stack (app + MySQL) on http://localhost:8080
	docker compose up -d --build

docker-down:     ## stop the Docker stack (keeps volumes)
	docker compose down

docker-logs:     ## follow the app + scheduler logs
	docker compose logs -f app

docker-smoke:    ## full Docker end-to-end test (fresh project, removed afterwards)
	bash tests/docker_smoke.sh
