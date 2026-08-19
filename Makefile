SHELL := /bin/bash
PHP ?= php
CONSOLE := $(PHP) bin/console
PORT ?= 8099

# The MCP spec lets a server send requests *back* to the client mid-tool-call
# (roots/list, sampling, elicitation). Over Streamable HTTP that needs two
# workers at once: one holding the SSE stream open, one taking the client's
# answer. PHP's built-in server is single-worker unless told otherwise, and a
# single worker deadlocks. See docs/deployment.md.
SERVER_WORKERS ?= 6

# The upstream branches this demo is built against.
SDK_REPO    := https://github.com/chr-hertel/php-sdk.git
SDK_REF     := 2026spec-findings
BUNDLE_REPO := https://github.com/chr-hertel/ai.git
BUNDLE_REF  := mcp-bundle-servers-and-clients

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# -- setup -------------------------------------------------------------------

.PHONY: upstream
upstream: ## Clone the two upstream branches into upstream/ and apply patches/
	@if [ -d upstream/php-sdk ]; then echo "upstream/php-sdk exists; remove it to re-clone."; \
	else git clone --branch $(SDK_REF) $(SDK_REPO) upstream/php-sdk; fi
	@if [ -d upstream/symfony-ai ]; then echo "upstream/symfony-ai exists; remove it to re-clone."; \
	else git clone --branch $(BUNDLE_REF) $(BUNDLE_REPO) upstream/symfony-ai; fi
	@$(MAKE) --no-print-directory apply-patches

# The verdict is taken over the series, not patch by patch: once two patches
# touch the same region, the earlier one stops reverse-applying on its own, and
# "fits neither way" stops meaning "upstream moved". See bin/apply-patches.
.PHONY: apply-patches
apply-patches: ## Apply every patch in patches/ to the upstream clones
	@bin/apply-patches upstream/symfony-ai patches/mcp-bundle/*.patch
	@bin/apply-patches upstream/php-sdk    patches/php-sdk/*.patch

.PHONY: upstream-check
upstream-check: ## Fail if either upstream branch has moved past the local clone
	@set -e; \
	git -C upstream/php-sdk    fetch -q origin $(SDK_REF); \
	git -C upstream/symfony-ai fetch -q origin $(BUNDLE_REF); \
	for entry in "php-sdk:$(SDK_REF)" "symfony-ai:$(BUNDLE_REF)"; do \
		dir=upstream/$${entry%%:*}; ref=$${entry##*:}; \
		local_sha=$$(git -C $$dir rev-parse HEAD); \
		remote_sha=$$(git -C $$dir rev-parse FETCH_HEAD); \
		if [ "$$local_sha" = "$$remote_sha" ]; then \
			printf 'up to date  %-28s %s\n' "$$dir" "$$(git -C $$dir rev-parse --short HEAD)"; \
		else \
			printf 'MOVED       %-28s %s -> %s\n' "$$dir" "$$(git -C $$dir rev-parse --short HEAD)" "$$(git -C $$dir rev-parse --short FETCH_HEAD)"; \
			echo "  Re-derive the patches against the new tip before tagging."; \
			exit 1; \
		fi; \
	done

.PHONY: export-patches
export-patches: ## Re-split patches/ from upstream/symfony-ai, verified against a clean clone
	@bin/export-patches

.PHONY: install
install: ## composer install (needs upstream/ to exist first)
	composer install

.PHONY: setup
setup: install db ## Full first-run setup

.PHONY: db
db: ## Recreate and seed the dev, test and prod databases
	$(CONSOLE) app:seed
	APP_ENV=test $(CONSOLE) app:seed
	# prod never rebuilds its container on a file change, so seeding with the one
	# left over from before the last edit fails on any changed constructor. Clear
	# first — cache:clear warms as it goes, which is what a host needs anyway.
	APP_ENV=prod $(CONSOLE) cache:clear
	APP_ENV=prod $(CONSOLE) app:seed

# -- running -----------------------------------------------------------------

.PHONY: serve
serve: ## Start the local web server (with enough workers for MCP round trips)
	PHP_CLI_SERVER_WORKERS=$(SERVER_WORKERS) symfony server:start -d --no-tls --port=$(PORT)
	@echo "MCP endpoints: http://127.0.0.1:$(PORT)/mcp, /mcp/organizer, /mcp/diagnostics"

.PHONY: stop
stop: ## Stop the local web server
	symfony server:stop

.PHONY: stdio
stdio: ## Run the conference server over STDIO, as a host would
	$(CONSOLE) mcp:server conference

# -- inspecting --------------------------------------------------------------

.PHONY: debug
debug: ## List what every configured MCP server exposes
	$(CONSOLE) debug:mcp

.PHONY: clients
clients: ## List the configured MCP clients and their servers
	$(CONSOLE) mcp:client:debug

.PHONY: claude-config
claude-config: ## Print the Claude Desktop configuration fragment
	$(CONSOLE) app:claude-desktop:config

# -- MCP Inspector -----------------------------------------------------------
# The reference client, fetched by npx. See docs/inspector.md.
# SERVER picks which of this application's servers to point it at.

SERVER ?= conference
INSPECTOR := npx -y @modelcontextprotocol/inspector

# The URL and the auth header the chosen server needs.
inspector_url = $(if $(filter conference,$(SERVER)),http://127.0.0.1:$(PORT)/mcp,http://127.0.0.1:$(PORT)/mcp/$(SERVER))
inspector_header = $(if $(filter organizer,$(SERVER)),--header "Authorization: Bearer $(shell grep -E '^MCP_DEMO_ORGANIZER_TOKEN=' .env | cut -d= -f2-)",)

.PHONY: inspector
inspector: ## Open the Inspector UI (needs `make serve`; SERVER=conference|organizer|diagnostics)
	@echo "Connect to $(inspector_url) over Streamable HTTP."
	@$(if $(filter organizer,$(SERVER)),echo 'Add the header: Authorization: Bearer <MCP_DEMO_ORGANIZER_TOKEN>',)
	$(INSPECTOR)

.PHONY: inspector-stdio
inspector-stdio: ## Open the Inspector UI against a STDIO server (no web server needed)
	$(INSPECTOR) $(PHP) bin/console mcp:server $(SERVER)

.PHONY: inspector-cli
inspector-cli: ## One Inspector CLI call, e.g. make inspector-cli ARGS='--method tools/list'
	@test -n "$(ARGS)" || { echo "Pass ARGS, e.g. ARGS='--method tools/list'"; exit 1; }
	@# Silent, so stdout is the server's JSON and nothing else — this target is
	@# meant to be piped into jq.
	@$(INSPECTOR) --cli $(inspector_url) --transport http $(inspector_header) $(ARGS)

.PHONY: inspector-tour
inspector-tour: ## Walk the whole MCP surface through the Inspector CLI, over HTTP
	@bin/inspector-tour

# -- checking ----------------------------------------------------------------

.PHONY: test
test: ## Run the PHPUnit suite (no web server needed)
	$(PHP) bin/phpunit

.PHONY: regression
regression: ## Run the regression suite against every connection (needs `make serve`)
	$(CONSOLE) app:mcp:regression

.PHONY: regression-stdio
regression-stdio: ## Run the regression suite over STDIO only (no web server needed)
	$(CONSOLE) app:mcp:regression --transport=stdio

.PHONY: regression-2026
regression-2026: ## Run the 2026-07-28 checks only (needs `make serve`)
	$(CONSOLE) app:mcp:regression modern

.PHONY: check
check: test regression-stdio ## Everything that runs without a web server

.PHONY: upstream-test
upstream-test: ## Run the upstream libraries' own test suites against the patched clones
	cd upstream/php-sdk && composer install --no-interaction --quiet && vendor/bin/phpunit --testsuite=unit
	cd upstream/symfony-ai/src/mcp-bundle && composer install --no-interaction --quiet && vendor/bin/phpunit
