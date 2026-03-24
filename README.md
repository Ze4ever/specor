# SPECOR

SPECOR e uma suite local em PHP + SQLite para controlo de portfolio cripto. Inclui apps separadas para Uniswap, Solana e NEXO, mais um dashboard global. O registo e feito pelo utilizador, sem wallet connect e sem leitura on-chain, com KPIs, historicos e atalhos operacionais.

## Porque usar
- Tudo corre localmente e os dados ficam em `data/app.sqlite`.
- Separacao clara por aplicacao (Uniswap, Solana, NEXO) e visao consolidada.
- Operacoes diretas: criar pools, registar fees, compor, fechar pools, staking, termos NEXO.
- Precos via Coingecko quando disponivel, com modo de preco definido pelo utilizador.

## Como correr (local)
1. Inicia o servidor:
   ```bash
   php -S localhost:8000 -t public
   ```
2. Abre `http://localhost:8000/index.php`.
3. Login inicial (se ainda nao houver utilizadores): `admin / admin123`.

## Configuracao inicial
- `APP_DEFAULT_USER` e `APP_DEFAULT_PASS` definem o utilizador inicial (so na primeira execucao).
- Timezone default: `Europe/Lisbon` (em `app/common/config/bootstrap.php`).
- Precisas de PHP 8+ com `pdo_sqlite` ativo e permissao de escrita em `data/`.

## Aplicacoes

### Global Dashboard
O que faz:
- Consolida Uniswap + NEXO num unico painel com KPIs e atividade recente.

Como funciona:
- Agrega dados das apps e mostra top pools, transacoes recentes e rewards recentes.

Como usar:
- Abre `index.php` e usa os atalhos para saltar para Uniswap ou NEXO.

Calcula:
- Total portfolio (Uniswap + NEXO).
- ROI, APR ponderado, fees 7d e rewards 30d.
- Snapshot de saude (pools com ROI positivo, yield > 1).

Vantagens:
- Visao rapida do portfolio sem abrir cada app.
- Links diretos para operacoes mais frequentes.

### Uniswap App
O que faz:
- Garante controlo completo de pools Uniswap com transacoes, fees, precos e encerramento de pools.

Como funciona:
- Usa tabelas locais para pools, transacoes, fee snapshots, targets e closed pools.

Como usar (fluxo recomendado):
1. `Market`: adiciona tokens e precos (Coingecko ou preco definido pelo utilizador).
2. `Create Pool`: cria um pool base (wallet, chain, assets).
3. `Transactions`: regista `create`, `compound`, `remove`, `fees` (ou importa JSON).
4. `Pools`: ajusta quantidades atuais, reordena pools e executa quick actions.
5. `Fees`: regista snapshots diarios de unclaimed e marca claims.
6. `Closed Pools`: fecha pools quando terminares a posicao.

Calcula:
- Deposits, totals, ROI, APR, P/day, HODL, fees claimed/unclaimed.
- Historico diario de fees (unclaimed + claimed).

Vantagens:
- Registo completo de performance por pool.
- Comparacao direta com HODL.
- Operacoes rapidas de compound/close.

### Solana App
O que faz:
- Replica o fluxo de pools/fees/transactions para Solana, com modulo de staking integrado.

Como funciona:
- Mesma logica de pools da app Uniswap, mas com tabelas dedicadas a Solana.

Como usar (fluxo recomendado):
1. `Market`, `Create Pool`, `Transactions`, `Pools`, `Fees`, `Closed Pools` seguem o mesmo fluxo da Uniswap.
2. `Staking`: adiciona posicoes, acompanha APY, rewards e fecha stakes.

Calcula:
- Mesmas metricas de pools da Uniswap.
- KPIs de staking (ativos, rewards, APY, valores USD).

Vantagens:
- Separacao clara entre liquidez e staking.
- Historico completo por stake com estado active/closed.

### NEXO Wallet App
O que faz:
- Controla wallet NEXO com termos flexible/fixed, precos de mercado e transacoes internas.

Como funciona:
- Guarda termos, rewards diarios, precos e transacoes por bucket (wallet/flexible/fixed).

Como usar (fluxo recomendado):
1. `Market`: define tokens e Coingecko IDs, ou preco definido pelo utilizador.
2. `Flexible`: cria termos, gera logs diarios e finaliza quando fechar.
3. `Fixed`: cria termos fixos, regista rewards diarios e fecha quando termina.
4. `Transactions`: regista movimentos de wallet e termos (add/remove/adjust/finalize).

Calcula:
- Total da wallet (EURX + NEXO), conversoes USD, rewards em USD e NEXO.
- APY medio ponderado, total diario e totais historicos.
- Logs diarios gerados a 00:00 UTC.

Vantagens:
- Visao consolidada da carteira NEXO.
- Historico detalhado de rewards por termo e por dia.

## Importacao e exportacao
- Import JSON na tab `Transactions` de Uniswap ou Solana.
- Import automatico se existir `data/excel_import.json`.
- Export JSON completo por utilizador.
- Export CSV de transacoes e asset prices.

Chaves esperadas no JSON:
- `asset_prices`, `dados_uniswap`, `pool_overrides`, `pool_order`, `token_targets`, `fee_snapshots`, `closed_pools`.

## Seguranca e dados
- Login/registo com passwords em hash.
- CSRF ativo em todos os POSTs.
- Dados isolados por utilizador em SQLite.

## Estrutura do projeto
- `public/` entrada web e assets.
- `app/common/` autenticacao, helpers e dashboard global.
- `app/uniswap/`, `app/solana/`, `app/nexo/` apps especificas.
- `data/` base SQLite e ficheiros de import.

## Licenca
Este projeto e proprietario. Ver `LICENSE`.
