<style>
    :root {
        --green: {{ $branding['theme_color'] }};
        --green-dark: {{ \App\Support\Branding::darken($branding['theme_color']) }};
        --primary-light: color-mix(in srgb, var(--green) 9%, white);
        --accent-foreground: {{ \App\Support\Branding::foreground($branding['theme_color']) }};
        --brand-name-color: {{ $branding['brand_name_color'] }};
        --brand-subtitle-color: {{ $branding['brand_subtitle_color'] }};
        --login-accent: {{ $branding['login_accent_color'] }};
        --login-accent-dark: {{ \App\Support\Branding::darken($branding['login_accent_color']) }};
        --login-foreground: {{ \App\Support\Branding::foreground($branding['login_accent_color']) }};
        --login-background: {{ $branding['login_background_color'] }};
        --login-title-color: {{ $branding['login_title_color'] }};
        --login-description-color: {{ $branding['login_description_color'] }};
    }
</style>
