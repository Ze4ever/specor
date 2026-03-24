# SPECOR

SPECOR is a local PHP + SQLite suite to manage a crypto portfolio. It ships with separate apps for Uniswap, Solana, and NEXO, plus a global dashboard that consolidates everything. No wallet connect and no on-chain reads are required.

## Why use SPECOR
- Runs fully local and stores data in `data/app.sqlite`.
- Clear separation per app (Uniswap, Solana, NEXO) with a global view.
- Direct operations: create pools, log fees, compound, close pools, staking, NEXO terms.
- Prices from Coingecko when available, with user-defined overrides.

## Run locally
1. Start the server:
   ```bash
   php -S localhost:8000 -t public
   ```
2. Open `http://localhost:8000/index.php`.
3. Initial login (only if no users exist yet): `admin / admin123`.

## Initial setup
- `APP_DEFAULT_USER` and `APP_DEFAULT_PASS` define the initial user (first run only).
- Default timezone: `Europe/Lisbon` in `app/common/config/bootstrap.php`.
- Requires PHP 8+ with `pdo_sqlite` enabled and write permissions to `data/`.

## Applications

### Global Dashboard
What it does:
- Consolidates Uniswap + NEXO into a single view with KPIs and recent activity.

How it works:
- Aggregates pool data and NEXO wallet data into a unified snapshot.

How to use:
- Open `index.php` and use the shortcuts to jump into the specific apps.

Calculations:
- Total portfolio (Uniswap + NEXO).
- ROI, weighted APR, fees (7d) and rewards (30d).
- Health snapshot (positive ROI pools, yield > 1).

Advantages:
- Fast overview without opening each app.
- Direct links to the most used actions.

### Uniswap App
What it does:
- Full pool control: transactions, fees, prices, and pool closing.

How it works:
- Local tables for pools, transactions, fee snapshots, targets, and closed pools.

How to use (recommended flow):
1. `Market`: add tokens and prices (Coingecko or user-defined).
2. `Create Pool`: create the base pool (wallet, chain, assets).
3. `Transactions`: log `create`, `compound`, `remove`, `fees` (or import JSON).
4. `Pools`: adjust current amounts, reorder pools, use quick actions.
5. `Fees`: add daily unclaimed snapshots and mark claims.
6. `Closed Pools`: close a pool when the position ends.

Calculations:
- Deposits, totals, ROI, APR, P/day, HODL, fees claimed/unclaimed.
- Daily fees history (unclaimed + claimed).

Advantages:
- Complete performance tracking per pool.
- Direct comparison vs HODL.
- Fast compound/close operations.

### Solana App
What it does:
- Mirrors the pool/fees/transactions flow for Solana, plus staking.

How it works:
- Same logic as Uniswap with Solana-specific tables.

How to use (recommended flow):
1. `Market`, `Create Pool`, `Transactions`, `Pools`, `Fees`, `Closed Pools` follow the same flow as Uniswap.
2. `Staking`: add positions, track APY, rewards, and close stakes.

Calculations:
- Same pool metrics as Uniswap.
- Staking KPIs (active count, rewards, APY, USD totals).

Advantages:
- Clear separation between liquidity and staking.
- Full history per stake with active/closed status.

### NEXO Wallet App
What it does:
- Tracks the NEXO wallet with flexible/fixed terms, market prices, and internal transactions.

How it works:
- Stores terms, daily rewards, market prices, and transactions by bucket (wallet/flexible/fixed).

How to use (recommended flow):
1. `Market`: define tokens and Coingecko IDs, or set a user-defined price.
2. `Flexible`: create terms, generate daily logs, finalize when closed.
3. `Fixed`: create fixed terms, record daily rewards, close at the end.
4. `Transactions`: log wallet and term movements (add/remove/adjust/finalize).

Calculations:
- Wallet total (EURX + NEXO), USD conversions, rewards in USD and NEXO.
- Weighted APY, daily pace, and historical totals.
- Daily logs generated at 00:00 UTC.

Advantages:
- Consolidated view of the NEXO wallet.
- Detailed rewards history per term and per day.

## Import and export
- Import JSON in the `Transactions` tab of Uniswap or Solana.
- Auto import if `data/excel_import.json` exists.
- Export full JSON backup per user.
- Export CSV for transactions and asset prices.

Expected JSON keys:
- `asset_prices`, `dados_uniswap`, `pool_overrides`, `pool_order`, `token_targets`, `fee_snapshots`, `closed_pools`.

## Security and data
- Login/registration with hashed passwords.
- CSRF enabled for all POST actions.
- User-isolated data in SQLite.

## Project structure
- `public/` web entry and assets.
- `app/common/` auth, helpers, and global dashboard.
- `app/uniswap/`, `app/solana/`, `app/nexo/` app modules.
- `data/` SQLite database and import files.

## License
This project is proprietary. See `LICENSE`.
