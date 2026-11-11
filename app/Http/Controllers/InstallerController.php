<?php

namespace App\Http\Controllers;

use App\Services\Installer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InstallerController extends Controller
{
    public function show(Request $request, Installer $installer)
    {
        $locked = $installer->locked();
        $canConfigure = $locked && $request->user()?->isAdministrator();
        $shops = config('installer.shops');
        $modules = config('installer.modules');
        $shop = $locked ? config('installer.shop_type') : 'supermarket';
        $selected = $locked
            ? array_keys(array_filter($modules, fn ($module) => config($module[2])))
            : $shops[$shop]['modules'];

        return view('installer.index', compact('locked', 'canConfigure', 'shops', 'modules', 'shop', 'selected'));
    }

    public function store(Request $request, Installer $installer)
    {
        $locked = $installer->locked();
        abort_if($locked && ! $request->user()?->isAdministrator(), 403);

        $moduleNames = array_keys(config('installer.modules'));
        $rules = [
            'shop_type' => ['required', Rule::in(array_keys(config('installer.shops')))],
            'module_selection' => 'sometimes|in:recommended,custom',
            'modules' => ['sometimes', 'array', 'list', 'max:'.count($moduleNames)],
            'modules.*' => ['required', 'string', 'distinct', Rule::in($moduleNames)],
        ];
        if ($locked) {
            $rules['installer_pin'] = ['required', 'string', 'max:128'];
        } else {
            $rules += [
                'shop_name' => 'required|string|max:120',
                'app_url' => ['required', 'url:http,https', 'max:255', 'regex:~^https?://[^/@?#]+(?:/[^?#]*)?$~'],
                'db_host' => ['required', 'string', 'max:255', 'regex:/\A(?:[a-zA-Z0-9.-]+|[a-fA-F0-9:]+)\z/'],
                'db_port' => 'required|integer|between:1,65535',
                'db_database' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_\$-]+\z/'],
                'db_username' => 'required|string|max:128',
                'db_password' => 'nullable|string|max:255',
                'admin_name' => 'required|string|max:120',
                'admin_username' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/'],
                'admin_email' => 'required|email|max:150',
                'password' => ['required', 'string', 'confirmed', 'min:10', function ($attribute, $value, $fail) {
                    if (strlen($value) > 72) {
                        $fail('Use a password of at most 72 bytes.');
                    }
                }],
                'sample_data' => 'sometimes|boolean',
            ];
        }
        $data = $request->validate($rules);
        $selection = $data['module_selection'] ?? (isset($data['modules']) ? 'custom' : 'recommended');
        if (! $locked && $selection === 'recommended') {
            $data['modules'] = config('installer.shops')[$data['shop_type']]['modules'];
        }
        if ($locked && ! $installer->matchesPin($data['installer_pin'])) {
            throw ValidationException::withMessages(['installer_pin' => 'Incorrect installer PIN.']);
        }
        if (in_array('CAM_BARCODE', $data['modules'] ?? [], true) && ! in_array('BARCODE', $data['modules'], true)) {
            throw ValidationException::withMessages(['modules' => 'Select Barcodes & labels to use camera scanning.']);
        }

        try {
            if ($locked) {
                $installer->reconfigure($data);

                return redirect()->route('installer.show')->with('installer_status', 'Module settings saved.');
            }

            $installer->install($data);
        } catch (RuntimeException $error) {
            logger()->error('Twin installer could not save configuration.', ['exception_type' => $error::class]);
            throw ValidationException::withMessages(['setup' => 'Could not save the configuration. Check write access to the application directory and storage.']);
        }

        return view('installer.complete');
    }
}
