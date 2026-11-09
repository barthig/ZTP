$ErrorActionPreference = 'Stop'
Push-Location (Join-Path $PSScriptRoot '..')

function Run-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "Polecenie docker nie powiodło się: $args" }
}

try {
    Run-Docker compose exec -T -e 'TEST_DATABASE_URL=postgresql+psycopg://lab2:lab2@db:5432/lab2' api python -m pytest -q
    Run-Docker compose exec -T api python scripts/demo.py
    foreach ($channel in @('email', 'push')) {
        $worker = "$channel-worker"
        Run-Docker compose stop $worker
        try {
            Run-Docker compose exec -T api python scripts/isolation.py create $channel
            Run-Docker compose exec -T api python scripts/isolation.py check
            if ($channel -eq 'email') {
                # Trwałe kolejki i baza muszą przetrwać restart usług.
                Run-Docker compose restart db rabbitmq
            }
        }
        finally {
            Run-Docker compose start $worker
        }
        Run-Docker compose exec -T api python scripts/isolation.py recover
    }
    Write-Host 'OK: wszystkie testy systemu.'
}
finally {
    Pop-Location
}
