<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Twin POS installation')</title>
    <style>
        :root { color-scheme: light; font: 16px/1.5 system-ui, sans-serif; color: #193139; background: #f1f6f5; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { background: #123538; color: #fff; }
        header .wrap { padding-block: 1.25rem; display: flex; align-items: center; gap: .75rem; font-weight: 750; }
        header span { display: grid; place-items: center; width: 2rem; height: 2rem; background: #b9edda; color: #123538; border-radius: .5rem; }
        .wrap { width: min(100% - 2rem, 68rem); margin-inline: auto; }
        main { padding-block: clamp(1.4rem, 5vw, 3rem); }
        h1, h2 { line-height: 1.2; margin: 0 0 .55rem; }
        h1 { font-size: clamp(1.8rem, 4vw, 2.65rem); letter-spacing: -.045em; }
        h2 { font-size: 1.22rem; }
        p { margin: 0 0 1rem; color: #48646a; }
        .intro { max-width: 43rem; margin-bottom: 1.5rem; }
        .columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(13rem, 17rem); gap: 1rem; align-items: start; }
        .stack { display: grid; gap: .9rem; }
        .panel { background: #fff; border: 1px solid #d3e1de; border-radius: .85rem; padding: clamp(1rem, 3vw, 1.45rem); }
        .summary { position: sticky; top: 1rem; }
        .summary ul, .notice ul { padding-left: 1.2rem; margin: .5rem 0 0; }
        .summary li + li { margin-top: .35rem; }
        a { color: #006c57; }
        label { display: grid; gap: .28rem; font-size: .92rem; font-weight: 650; min-width: 0; }
        small { display: block; color: #48646a; font-size: .82rem; font-weight: 400; }
        input:not([type=checkbox]), select { width: 100%; min-width: 0; min-height: 2.75rem; background: #fff; border: 1px solid #a9bfbb; border-radius: .5rem; font: inherit; color: inherit; padding: .5rem .7rem; }
        input[type=checkbox] { accent-color: #08735c; width: 1.1rem; height: 1.1rem; margin: .15rem 0 0; }
        :focus-visible { outline: 2px solid #08735c; outline-offset: 2px; }
        .fields, .modules { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem; }
        .wide { grid-column: 1 / -1; }
        .choice { grid-template-columns: auto minmax(0, 1fr); align-items: start; border: 1px solid #d6e4e0; border-radius: .6rem; background: #f8fbfa; padding: .65rem; cursor: pointer; }
        [hidden] { display: none !important; }
        .module-mode { margin-bottom: .8rem; }
        .choice:has(:checked) { border-color: #77b6a4; background: #eff8f3; }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; }
        .button { border: 0; border-radius: .55rem; padding: .65rem 1rem; min-height: 2.75rem; font: inherit; font-weight: 700; cursor: pointer; color: white; background: #086752; text-decoration: none; }
        .button:disabled { opacity: .6; cursor: progress; }
        .notice { padding: .8rem 1rem; border-radius: .6rem; color: #86291c; background: #fff1ed; margin-bottom: 1rem; }
        .notice.success { color: #115744; background: #def4e9; }
        code { overflow-wrap: anywhere; }
        @media (max-width: 760px) { .columns { grid-template-columns: minmax(0, 1fr); } .summary { position: static; } }
        @media (max-width: 510px) { .fields, .modules { grid-template-columns: minmax(0, 1fr); } .button { width: 100%; text-align: center; } }
    </style>
</head>
<body>
<header><div class="wrap"><span aria-hidden="true">T</span>Twin POS setup</div></header>
<main class="wrap">@yield('content')</main>
@yield('scripts')
</body>
</html>
