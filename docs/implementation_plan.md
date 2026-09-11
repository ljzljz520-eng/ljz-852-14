# Fix Database Connection Lifecycle

## Goal
Resolve the `PDOException: Connection refused` error caused by the `app` and `crawler` services attempting to connect to the MySQL database before it is fully ready.

## User Review Required
> [!NOTE]
> This change relies on the `healthcheck` defined in the `db` service in `docker-compose.yml`.

## Proposed Changes

### Configuration
#### [MODIFY] [docker-compose.yml](file:///Users/jack.yan/Downloads/labeleases/stage03/852/manticore-search-engine/docker-compose.yml)
- Update `depends_on` for `app` and `crawler` services.
- Change from simple list to detailed definition with `condition: service_healthy` for `db`.

## Verification Plan

### Automated Tests
- None available for this infrastructure change.

### Manual Verification
1.  User runs `docker compose up --build`.
2.  Observe logs for `app` and `crawler`.
3.  Confirm `init.php` executes successfully without `Connection refused` error.
