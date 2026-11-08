<?php

return [
    'receive-a-repair' => [
        'title' => 'Receive and track a device', 'category' => 'Phone repairs', 'icon' => 'settings-2', 'minutes' => 4,
        'description' => 'Create an intake, check the device and print a tracking receipt.',
        'path' => 'Phone repairs → Receive device', 'before' => 'Ask which faults the customer noticed and which accessories they left with you.',
        'tip' => 'A price is optional on the intake receipt. Revenue posts when you finalize the invoice.',
        'requires' => ['phone_rep'],
        'link' => ['route' => 'repairs.create', 'permission' => ['repairs.view', 'repairs.create'], 'parameters' => []],
        'steps' => [
            ['title' => 'Select the customer and device', 'text' => 'Search for the customer, then pick a saved device or select its brand and model. Use manual entry if the device is missing. Choose the reported issues and continue.'],
            ['title' => 'Inspect and receive', 'text' => 'Tap Works, Issue or Skip for the checks you can do. Pick the visible condition and items received, then review and save the repair.'],
            ['title' => 'Print and share tracking', 'text' => 'Open Print, select Intake receipt and choose 80mm, A5 or A4. The customer can enter the tracking number on the public tracking page to check the repair status.'],
        ],
    ],
    'manage-repair-jobs' => [
        'title' => 'Diagnose, repair and deliver', 'category' => 'Phone repairs', 'icon' => 'settings-2', 'minutes' => 5,
        'description' => 'Assign work, record approval, consume parts and complete the job.',
        'path' => 'Phone repairs → Repair jobs', 'before' => 'Use an account with permission to edit jobs or manage parts.',
        'tip' => 'Mark a part consumed when it is fitted or used. Removing it before invoicing returns the recorded quantity to stock.',
        'requires' => ['phone_rep'],
        'link' => ['route' => 'repairs.index', 'permission' => 'repairs.view', 'parameters' => []],
        'steps' => [
            ['title' => 'Assign and diagnose', 'text' => 'Open the job and select a technician, priority and due date. Move to Diagnosing, record the findings and save the estimate when you have it.'],
            ['title' => 'Record approval and parts', 'text' => 'Record the customer’s approval when they accept the quote. Enable Available for Repairs on stock products. Plan the required quantities in the job, then mark the parts consumed as work progresses.'],
            ['title' => 'Finalize and hand over', 'text' => 'Review labor, fees, discounts, tax and costs. Mark the device Ready and then Completed to issue the invoice. Record delivery at handover to start the warranty period and clear the stored unlock credential.'],
        ],
    ],
    'manage-repair-payments-and-reports' => [
        'title' => 'Repair payments and reports', 'category' => 'Phone repairs', 'icon' => 'wallet', 'minutes' => 4,
        'description' => 'Take advances and final payments, correct entries and review job profit.',
        'path' => 'Phone repairs → Repair jobs and Repair reports', 'before' => 'Open your register before posting cash.',
        'tip' => 'An invoiced refund posts a credit against the invoice. A payment correction records a reversal and a replacement.',
        'requires' => ['phone_rep'],
        'link' => ['route' => 'repairs.reports', 'permission' => 'repairs.reports', 'parameters' => []],
        'steps' => [
            ['title' => 'Post an advance or payment', 'text' => 'In Billing & payments, choose Take advance before invoicing or Collect payment afterward. Enter the amount and method, then print the payment receipt.'],
            ['title' => 'Correct or refund a payment', 'text' => 'Choose Correct next to the original entry and give a reason for the replacement. Use Record refund when returning money. Refund remaining advances before cancelling an uninvoiced job.'],
            ['title' => 'Review and export', 'text' => 'Open Repair reports and pick a report. Use date and job filters, then Export CSV. The consolidated profit statement includes invoiced repair revenue, parts costs, direct costs and credits.'],
        ],
    ],
    'configure-phone-repairs' => [
        'title' => 'Repair settings and staff access', 'category' => 'Phone repairs', 'icon' => 'shield-check', 'minutes' => 3,
        'description' => 'Set intake terms and choose what each role can do.',
        'path' => 'Settings → Repair settings', 'before' => 'An administrator assigns repair permissions from the role editor.',
        'tip' => 'Grant unlock access only to staff who need to test customer devices.',
        'requires' => ['phone_rep'],
        'link' => ['route' => 'repairs.settings', 'permission' => 'repairs.settings', 'parameters' => []],
        'steps' => [
            ['title' => 'Set printed terms', 'text' => 'In Phone repairs, open Repair settings to set the paper size, layout and terms. Preview a sample receipt before saving. Open Device catalog to add or archive brands and models.'],
            ['title' => 'Set permissions', 'text' => 'Open Roles & permissions, choose a role and grant the repair actions it needs. Repair jobs follow owner and role visibility. Assigned technicians can open their jobs if they have repair access.'],
        ],
    ],
    'configure-repair-sms' => [
        'title' => 'Send repair SMS updates', 'category' => 'Phone repairs', 'icon' => 'send', 'minutes' => 2,
        'description' => 'Choose which repair events send messages to customers who consent.',
        'path' => 'Settings → SMS', 'before' => 'Configure the Textit account and enable SMS sending.',
        'tip' => 'Keep message templates focused on the tracking number, status and collection details.',
        'requires' => ['repair_sms'],
        'link' => ['route' => 'settings.sms', 'permission' => 'sms.settings', 'parameters' => []],
        'steps' => [
            ['title' => 'Enable message types', 'text' => 'Open Phone repairs → Repair settings. Turn on Enable repair SMS and choose the notifications you want to send. Use the SMS account and templates link to review the message wording.'],
            ['title' => 'Confirm customer consent', 'text' => 'Tick the consent checkbox only if the customer agrees. Staff can withdraw consent on the job. Check SMS history for queued and sent updates.'],
        ],
    ],
];
