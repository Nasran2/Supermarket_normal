<?php

return [
    'brand_name' => ['Main name', 'text', '', 'nullable|string|max:150'],
    'brand_subtitle' => ['Second name / subtitle', 'text', 'SUPERMARKET POS', 'nullable|string|max:100'],
    'login_brand_text' => ['Login brand heading', 'text', '', 'nullable|string|max:180'],
    'login_title' => ['Login welcome title', 'text', 'Welcome back', 'required|string|max:100'],
    'login_description' => ['Login description', 'text', 'Sign in to continue to your POS.', 'nullable|string|max:250'],
    'theme_color' => ['App accent color', 'color', '#047857', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'brand_name_color' => ['Main name color', 'color', '#172b27', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'brand_subtitle_color' => ['Second name color', 'color', '#96a39b', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'login_accent_color' => ['Login accent color', 'color', '#0db168', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'login_background_color' => ['Login background color', 'color', '#e6fcf0', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'login_title_color' => ['Login welcome title color', 'color', '#111827', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'login_description_color' => ['Login description color', 'color', '#6b7280', 'required|regex:/^#[0-9a-fA-F]{6}$/'],
    'login_icon_mode' => ['Login image style', 'choice', 'business-logo', 'required|in:business-logo,icon,custom-logo'],
    'login_icon' => ['Login icon', 'choice', 'shopping-basket', 'required|in:shopping-basket,shopping-bag,store,shopping-cart,scan-line,receipt-text,building-2,package,shield-check'],
    'login_logo' => ['Custom login image (PNG, JPG, WEBP · max 2 MB)', 'file', null, 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048'],
];
