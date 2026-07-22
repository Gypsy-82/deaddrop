# Convenience wrapper around docker compose. `docker compose ...` works directly too
# (it falls back to the defaults baked into docker-compose.yml).
SHELL := /bin/bash   # `read -s` (hidden input) for `make secret`

# All settings live in config/settings.env — this makes compose read it for
# ${SITE_ADDRESS}/${HTTP_PORT}/... interpolation as well.
DC := docker compose --env-file config/settings.env

CLIENT ?= client
TTL    ?=
BURN   ?= 1
# Default share base follows the configured HTTPS port (rootless-safe 8443 out of the box).
DDPORT := $(shell grep -E '^HTTPS_PORT=' config/settings.env 2>/dev/null | cut -d= -f2)
BASE   ?= https://localhost:$(or $(DDPORT),8443)

.DEFAULT_GOAL := help
.PHONY: help setup config up down restart logs ps build rebuild destroy shell url \
        share secret file messages unread read links revoke rotate

help: ## Show this help
	@echo ""
	@echo "  Deaddrop — hardened local dead drop"
	@echo "  ─────────────────────────────────"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "  Configure:  make setup   ·   apply:  make up   ·   view:  make config"
	@echo "  e.g.  make share CLIENT=\"Acme\" TTL=48h  ·  make secret  ·  make messages"
	@echo ""

setup: ## Guided setup — pick mode/address/lifetime, writes config/settings.env
	@bash scripts/setup.sh

config: ## Show the current settings
	@echo ""
	@echo "  Deaddrop settings (config/settings.env):"
	@grep -E '^[A-Z]' config/settings.env | sed 's/^/    /'
	@echo ""

up: ## Start the server — Docker OR rootless Podman (starts Podman's socket if needed)
	@bash scripts/up.sh

down: ## Stop the server (all session links die; data is kept)
	$(DC) down

restart: ## Restart (rotates the obfuscated URL; old links die)
	$(DC) restart

logs: ## Follow container logs
	$(DC) logs -f

ps: ## Show container status
	$(DC) ps

build: ## Build the PHP image
	$(DC) build

rebuild: ## Rebuild the PHP image from scratch
	$(DC) build --no-cache

destroy: ## Stop AND delete all data (SQLite + certs). Irreversible.
	$(DC) down -v

shell: ## Open a shell in the PHP container
	$(DC) exec php sh

url: ## Print this session's obfuscated base URL
	@$(DC) exec -T php sh -c 'echo "$(BASE)/$$(cat /tmp/session/prefix)/"'

share: ## Mint a feedback link. Vars: CLIENT, TTL, BASE
	@$(DC) exec -T php php /var/www/html/bin/share.php --client="$(CLIENT)" $(if $(TTL),--ttl="$(TTL)",) --base="$(BASE)"

secret: ## Encrypt a text secret to share (secure-share mode). Vars: TTL, BURN, BASE
	@read -r -p "Label: " LBL; \
	 read -r -s -p "Secret: " SEC; echo; \
	 read -r -s -p "Passphrase (send out-of-band): " PASS; echo; \
	 printf '%s\n%s\n%s\n' "$$LBL" "$$SEC" "$$PASS" \
	   | $(DC) exec -T php php /var/www/html/bin/secret.php --ttl="$(TTL)" --burn="$(BURN)" --base="$(BASE)"

file: ## Encrypt a FILE to share (secure-share mode). Vars: FILE, TTL, BURN, BASE
	@test -n "$(FILE)" || { echo "usage: make file FILE=./path/to/file [TTL=24h]"; exit 1; }
	@test -f "$(FILE)" || { echo "no such file: $(FILE)"; exit 1; }
	@read -r -s -p "Passphrase (send out-of-band): " PASS; echo; \
	 { printf '%s\n' "$$PASS"; cat "$(FILE)"; } \
	   | $(DC) exec -T php php /var/www/html/bin/file.php --name="$$(basename '$(FILE)')" --ttl="$(TTL)" --burn="$(BURN)" --base="$(BASE)"

messages: ## Dump all client messages to the terminal
	@$(DC) exec php php /var/www/html/bin/messages.php

unread: ## Dump only unread messages
	@$(DC) exec php php /var/www/html/bin/messages.php --unread

read: ## Dump messages, then mark them read
	@$(DC) exec php php /var/www/html/bin/messages.php --mark-read

links: ## List every minted share link
	@$(DC) exec php php /var/www/html/bin/links.php

revoke: ## Revoke a link now. Var: ID
	@$(DC) exec -T php php /var/www/html/bin/links.php --revoke=$(ID)

rotate: ## Rotate the obfuscated URL now (kills all links, no restart)
	@$(DC) exec -T php php /var/www/html/bin/rotate.php
