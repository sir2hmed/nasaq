#!/usr/bin/env sh
set -eu

SCRIPT_DIR=${0%/*}
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
cd "$ROOT"

node -e "const fs=require('fs'); const required=['README.md','PROJECT_STATUS.md','CHANGELOG.md','REQUIREMENTS_MATRIX.md','docker-compose.yml','sample_workflow.json','docs/architecture.md','docs/api-contracts.md','docs/agent-contracts.md','docs/schemas/workflow.schema.json','frontend/Dockerfile','backend-laravel/Dockerfile','ai-service-python/Dockerfile']; const missing=required.filter(file=>!fs.existsSync(file)); if(missing.length) throw new Error('Missing required files: '+missing.join(', ')); const w=JSON.parse(fs.readFileSync('sample_workflow.json','utf8')); const s=JSON.parse(fs.readFileSync('docs/schemas/workflow.schema.json','utf8')); if(w.version!==1||!w.nodes.length) throw new Error('invalid sample workflow'); const ids=new Set(w.nodes.map(n=>n.id)); if(ids.size!==w.nodes.length||w.edges.some(e=>!ids.has(e.source)||!ids.has(e.target))) throw new Error('invalid graph references'); if(s['\u0024schema']!=='https://json-schema.org/draft/2020-12/schema') throw new Error('invalid schema draft'); const matrix=fs.readFileSync('REQUIREMENTS_MATRIX.md','utf8'); for(const id of ['FR-01','FR-02','FR-03','FR-04','FR-05','FR-06','NFR-01','NFR-02','NFR-03','NFR-04']) if(!matrix.includes(id)) throw new Error('Requirements matrix is missing '+id);"

if command -v npm >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
  npm --prefix frontend run lint
  npm --prefix frontend test
  npm --prefix frontend run build

  (cd ai-service-python && python3 -m ruff check .)
  (cd ai-service-python && python3 -m ruff format --check .)
  (cd ai-service-python && python3 -m pytest)

  (cd backend-laravel && php vendor/bin/pint --test)
  (cd backend-laravel && php artisan test)
fi

echo "All available Nasaq AI test suites passed."
