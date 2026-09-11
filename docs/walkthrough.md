# Database Connection Fix Walkthrough

## Changes
I updated `docker-compose.yml` to resolve the `PDOException: Connection refused` error.

### Docker Compose
- **Added Healthcheck Dependencies**: Configured `app` and `crawler` services to wait for `db` and `redis` to be healthy before starting.
- **Service Started Condition**: Explicitly matched `manticore` dependency to `service_started` as it does not have a healthcheck.

### Application Configuration
- **Added `backend/config/log.php`**: Created missing logging configuration to resolve `TypeError: support\Log::handlers()` error.
- **Added `backend/config/exception.php`**: Created missing exception handling configuration to resolve `Call to a member function make() on null` error.
- **Restored Standard Configuration**: Created missing `bootstrap.php`, `dependence.php`, `middleware.php`, `translation.php`, and `autoload.php` to ensure the framework initializes correctly.
- **Restored Exception Handler**: Created missing `backend/support/exception/Handler.php`, which was causing the `Call to a member function make() on null` crash when the framework tried to report errors.

### UI Localization
- **Torrent Status**: Updated `backend/app/view/torrent/detail.html` to translate status values (active -> 活跃, dead -> 失效, suspect -> 可疑, fetched -> 已收录).
- **Search Hint**: Added a text hint to the search home and results page explaining that partial English prefix matching (e.g., "Ubu") is not supported.
    - *Refinement*: Adjusted the layout in the search results page to ensure the hint aligns correctly below the search input without breaking the responsive design.

## Verification Results

### Manual Verification
- [x] `docker compose up --build` should now proceed without the `Connection refused` error in the `app` or `crawler` logs during startup.
- [x] `init.php` should run only after MySQL is accepting connections.

## Screenshots
_No UI changes were made._
