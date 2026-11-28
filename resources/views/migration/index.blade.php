<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Database Migrations & Maintenance</title>
    <style>
        :root {
            color-scheme: light;
            font: 15px/1.55 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1e293b;
            background: #f1f5f9;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding-bottom: 3rem; }
        .topbar {
            background: #0f172a;
            color: #f8fafc;
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid #1e293b;
        }
        .topbar .wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 700;
            font-size: 1.05rem;
        }
        .brand-badge {
            display: grid;
            place-items: center;
            width: 2rem;
            height: 2rem;
            background: #2563eb;
            color: #fff;
            border-radius: 0.5rem;
            font-weight: 800;
            font-size: 0.95rem;
        }
        .wrap {
            max-width: 68rem;
            margin-inline: auto;
            padding-inline: 1.25rem;
        }
        .main-content {
            padding-top: 1.75rem;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .card-title {
            margin: 0 0 0.85rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            text-align: center;
        }
        .stat-box {
            padding: 0.75rem 0.5rem;
            border-radius: 0.5rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1.2;
            color: #0f172a;
        }
        .stat-value.pending { color: #d97706; }
        .stat-value.ran { color: #16a34a; }
        .stat-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            font-weight: 600;
            color: #64748b;
            margin-top: 0.25rem;
        }
        .db-info-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 0.45rem;
            font-size: 0.88rem;
        }
        .db-info-item {
            display: flex;
            justify-content: space-between;
            padding-bottom: 0.4rem;
            border-bottom: 1px dashed #f1f5f9;
        }
        .db-info-item:last-child { border-bottom: none; }
        .db-info-label { color: #64748b; font-weight: 500; }
        .db-info-val { font-weight: 600; color: #0f172a; font-family: ui-monospace, SFMono-Regular, monospace; }

        .alert {
            padding: 0.9rem 1.15rem;
            border-radius: 0.65rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.93rem;
            font-weight: 500;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-info { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid transparent;
            border-radius: 0.5rem;
            padding: 0.65rem 1.25rem;
            font-size: 0.92rem;
            font-weight: 650;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover { background: #15803d; }
        .btn-secondary { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; color: #0f172a; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 0.4rem 0.75rem; font-size: 0.82rem; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }

        .actions-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
        }

        .terminal {
            background: #090d16;
            color: #4ade80;
            font-family: ui-monospace, SFMono-Regular, "Roboto Mono", Menlo, Monaco, monospace;
            font-size: 0.84rem;
            line-height: 1.5;
            padding: 1rem 1.25rem;
            border-radius: 0.65rem;
            border: 1px solid #1e293b;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 380px;
            margin-bottom: 1.25rem;
        }
        .terminal-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.65rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #1e293b;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .terminal-dot { width: 0.6rem; height: 0.6rem; border-radius: 50%; display: inline-block; }
        .dot-red { background: #ef4444; }
        .dot-yellow { background: #f59e0b; }
        .dot-green { background: #10b981; }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 0.65rem;
            background: #fff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            text-align: left;
        }
        th, td {
            padding: 0.7rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        th {
            background: #f8fafc;
            color: #475569;
            font-weight: 650;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-secondary { background: #f1f5f9; color: #64748b; }

        .filter-nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
        }
        .filter-btn {
            background: #fff;
            border: 1px solid #cbd5e1;
            padding: 0.35rem 0.85rem;
            border-radius: 0.5rem;
            font-size: 0.83rem;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.1s ease;
        }
        .filter-btn.active {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }
        .search-input {
            padding: 0.45rem 0.75rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            min-width: 220px;
            margin-left: auto;
        }
        .search-input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 1px;
        }

        .auth-card {
            max-width: 28rem;
            margin: 2rem auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.85rem;
            padding: 1.75rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07);
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
        }
        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            font: inherit;
        }
        .form-control:focus {
            outline: 2px solid #2563eb;
            outline-offset: 1px;
            border-color: #2563eb;
        }
        .muted-tip {
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 0.4rem;
        }
        .tip-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.65rem;
            padding: 0.85rem 1.1rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: #475569;
        }
        .tip-box code {
            background: #e2e8f0;
            color: #0f172a;
            padding: 0.15rem 0.4rem;
            border-radius: 0.3rem;
            font-size: 0.85em;
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="wrap">
        <div class="brand">
            <span class="brand-badge">M</span>
            <span>Database Migration & Maintenance</span>
        </div>
        <div>
            @if(auth()->check())
                <span style="font-size: 0.85rem; color: #94a3b8; margin-right: 0.75rem;">Logged in: <strong>{{ auth()->user()->name }}</strong></span>
                <a href="{{ route('dashboard') }}" class="btn btn-secondary btn-sm" style="background:#1e293b; color:#fff; border-color:#334155;">Back to POS</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-secondary btn-sm" style="background:#1e293b; color:#fff; border-color:#334155;">Login</a>
            @endif
        </div>
    </div>
</header>

<main class="wrap main-content">

    @if(session('success'))
        <div class="alert alert-success">
            <span>✓</span>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">
            <span>⚠️</span>
            <div>{{ session('error') }}</div>
        </div>
    @endif

    @if(session('info'))
        <div class="alert alert-info">
            <span>ℹ️</span>
            <div>{{ session('info') }}</div>
        </div>
    @endif

    @if($requiresUnlock)
        {{-- PIN Unlock Form --}}
        <div class="auth-card">
            <h2 style="margin: 0 0 0.5rem; font-size: 1.35rem; color: #0f172a;">Authentication Required</h2>
            <p style="margin: 0 0 1.25rem; font-size: 0.9rem; color: #64748b;">
                This migration panel is protected. Enter your Migration PIN or log in with your Administrator credentials.
            </p>

            <form action="{{ route('migration.unlock') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label class="form-label" for="pin">Migration PIN / Secret Key</label>
                    <input type="password" id="pin" name="pin" class="form-control" placeholder="Enter migration PIN" autofocus autocomplete="current-password">
                </div>

                <div style="text-align: center; margin: 1rem 0; font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 700;">
                    — OR ADMINISTRATOR LOGIN —
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">Username or Email</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="admin">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Password">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">Unlock Panel</button>
            </form>
        </div>
    @else
        {{-- Database & Migration Status --}}
        <div class="grid-2">
            <div class="card">
                <div class="card-title">
                    <span>Database Connection</span>
                    @if($dbConnected)
                        <span class="badge badge-success">● Connected</span>
                    @else
                        <span class="badge badge-warning" style="background:#fee2e2; color:#b91c1c;">● Disconnected</span>
                    @endif
                </div>
                <ul class="db-info-list">
                    <li class="db-info-item">
                        <span class="db-info-label">Driver:</span>
                        <span class="db-info-val">{{ strtoupper($dbConfig['driver']) }}</span>
                    </li>
                    <li class="db-info-item">
                        <span class="db-info-label">Database Name:</span>
                        <span class="db-info-val">{{ $dbConfig['database'] }}</span>
                    </li>
                    <li class="db-info-item">
                        <span class="db-info-label">Host & Port:</span>
                        <span class="db-info-val">{{ $dbConfig['host'] }}:{{ $dbConfig['port'] }}</span>
                    </li>
                    <li class="db-info-item">
                        <span class="db-info-label">Environment:</span>
                        <span class="db-info-val">{{ app()->environment() }}</span>
                    </li>
                </ul>
                @if($dbError)
                    <div class="alert alert-error" style="margin-top: 0.85rem; padding: 0.5rem 0.75rem; font-size: 0.82rem;">
                        {{ $dbError }}
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-title">
                    <span>Migration Summary</span>
                    @if($pendingCount > 0)
                        <span class="badge badge-warning">{{ $pendingCount }} Pending</span>
                    @else
                        <span class="badge badge-success">Up to Date</span>
                    @endif
                </div>
                <div class="stat-group">
                    <div class="stat-box">
                        <div class="stat-value">{{ $totalCount }}</div>
                        <div class="stat-label">Total Files</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value ran">{{ $ranCount }}</div>
                        <div class="stat-label">Applied</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value pending">{{ $pendingCount }}</div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pending Alert Banner --}}
        @if($pendingCount > 0)
            <div class="alert alert-warning" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <strong>⚠️ Pending Migrations Detected:</strong> There are <strong>{{ $pendingCount }}</strong> migration(s) waiting to be executed on this database.
                </div>
                <form action="{{ route('migration.run') }}" method="POST" onsubmit="return confirm('Execute {{ $pendingCount }} pending migration(s) on the database?');" style="margin: 0;">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm" style="font-weight: 700;">
                        ▶ Run {{ $pendingCount }} Pending Migration(s)
                    </button>
                </form>
            </div>
        @else
            <div class="alert alert-success">
                <span>✓</span>
                <div>
                    <strong>Database is up to date!</strong> All <strong>{{ $totalCount }}</strong> migration files have been successfully executed.
                </div>
            </div>
        @endif

        {{-- Actions Toolbar --}}
        <div class="actions-bar">
            <form action="{{ route('migration.run') }}" method="POST" onsubmit="return confirm('Run database migrations now?');" style="margin: 0;">
                @csrf
                <button type="submit" class="btn btn-primary" id="btn-run-migrations">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    Run Migrations
                </button>
            </form>

            <form action="{{ route('migration.cache') }}" method="POST" style="margin: 0;">
                @csrf
                <button type="submit" class="btn btn-secondary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M3 21v-5h5"></path></svg>
                    Clear All Cache
                </button>
            </form>

            <form action="{{ route('migration.storage-link') }}" method="POST" style="margin: 0;">
                @csrf
                <button type="submit" class="btn btn-secondary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    Storage Link
                </button>
            </form>

            <a href="{{ route('migration.index') }}" class="btn btn-secondary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                Refresh
            </a>

            @if($hasPinConfigured)
                <form action="{{ route('migration.lock') }}" method="POST" style="margin: 0; margin-left: auto;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm" title="Lock migration panel">
                        🔒 Lock Panel
                    </button>
                </form>
            @endif
        </div>

        {{-- Console Output Terminal if available --}}
        @if(session('console_output'))
            <div class="card" style="padding: 1rem; margin-bottom: 1.25rem;">
                <div class="terminal-header">
                    <span class="terminal-dot dot-red"></span>
                    <span class="terminal-dot dot-yellow"></span>
                    <span class="terminal-dot dot-green"></span>
                    <span style="margin-left: 0.5rem;">Console Execution Output</span>
                </div>
                <div class="terminal">{{ session('console_output') }}</div>
            </div>
        @endif

        {{-- Migrations List Table --}}
        <div class="card">
            <div class="filter-nav">
                <button type="button" class="filter-btn active" data-filter="all">All ({{ $totalCount }})</button>
                <button type="button" class="filter-btn" data-filter="pending">Pending ({{ $pendingCount }})</button>
                <button type="button" class="filter-btn" data-filter="ran">Applied ({{ $ranCount }})</button>
                <input type="text" id="migration-search" class="search-input" placeholder="Search migrations...">
            </div>

            <div class="table-wrap">
                <table id="migrations-table">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Status</th>
                            <th>Migration Name</th>
                            <th style="width: 90px; text-align: center;">Batch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($migrations as $m)
                            <tr class="migration-row" data-status="{{ $m['ran'] ? 'ran' : 'pending' }}">
                                <td>
                                    @if($m['ran'])
                                        <span class="badge badge-success">✓ Applied</span>
                                    @else
                                        <span class="badge badge-warning">● Pending</span>
                                    @endif
                                </td>
                                <td>
                                    <strong style="color: {{ $m['ran'] ? '#334155' : '#b45309' }};">
                                        {{ $m['name'] }}
                                    </strong>
                                </td>
                                <td style="text-align: center; color: #64748b;">
                                    {{ $m['batch'] ? '[' . $m['batch'] . ']' : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 2rem; color: #64748b;">
                                    No migration files found in database/migrations.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Informational Tip Box --}}
        <div class="tip-box">
            <strong>🔒 Security & Configuration Tip:</strong>
            @if($hasPinConfigured)
                This migration tool is currently protected by a security PIN. You can change it anytime in your <code>.env</code> file under <code>MIGRATION_PIN=your_secret</code>.
            @else
                To restrict access to this page with a secret PIN, add <code>MIGRATION_PIN=your_secret_pin</code> to your <code>.env</code> file on the server. You can also run migrations directly by visiting <code>/migration?key=your_secret_pin</code>.
            @endif
        </div>
    @endif

</main>

<script>
    // Migration table filtering & search
    document.addEventListener('DOMContentLoaded', function () {
        const filterBtns = document.querySelectorAll('.filter-btn');
        const searchInput = document.getElementById('migration-search');
        const rows = document.querySelectorAll('.migration-row');
        let currentFilter = 'all';

        function applyFilters() {
            const query = (searchInput?.value || '').trim().toLowerCase();

            rows.forEach(function (row) {
                const status = row.getAttribute('data-status');
                const text = row.textContent.toLowerCase();

                const matchesFilter = (currentFilter === 'all') || (status === currentFilter);
                const matchesSearch = !query || text.includes(query);

                if (matchesFilter && matchesSearch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                filterBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentFilter = this.getAttribute('data-filter');
                applyFilters();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
    });
</script>

</body>
</html>
