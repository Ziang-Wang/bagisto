<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Best-effort migration of customers from the legacy store.
 *
 * The source store takes guest orders (no registered accounts), so this builds
 * Bagisto customer records from the unique billing e-mails found on the orders.
 * Each customer gets a random password and is left unverified; the owner can
 * trigger a password reset later. This step is entirely optional.
 */
class CustomerMigrator
{
    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping,
        protected CustomerRepository $customerRepository
    ) {}

    /**
     * Run the customer migration.
     */
    public function migrate(Command $console): void
    {
        $contacts = $this->collectContacts();

        if (empty($contacts)) {
            $console->warn('  No customer e-mails found on the legacy orders.');

            return;
        }

        $groupId = (int) config('woo-importer.defaults.customer_group_id', 2);
        $channelId = (int) config('woo-importer.defaults.channel_id', 1);

        $created = $skipped = 0;

        foreach ($contacts as $email => $contact) {
            if ($this->customerRepository->findOneByField('email', $email)) {
                $skipped++;

                continue;
            }

            $customer = $this->customerRepository->create([
                'first_name' => $contact['first_name'] ?: Str::before($email, '@'),
                'last_name' => $contact['last_name'] ?: ' ',
                'email' => $email,
                'phone' => $contact['phone'] ?: null,
                'password' => Hash::make(Str::random(32)),
                'customer_group_id' => $groupId,
                'channel_id' => $channelId,
                'status' => 1,
                'is_verified' => 0,
                'subscribed_to_news_letter' => 0,
            ]);

            $this->mapping->put(Mapping::ENTITY_CUSTOMER, $email, $customer->id);

            $created++;
        }

        $console->info("  Customers created: {$created} — skipped (already existed): {$skipped}");
    }

    /**
     * Build [email => [first_name, last_name, phone]] from the HPOS order tables.
     *
     * @return array<string, array<string, string|null>>
     */
    protected function collectContacts(): array
    {
        $contacts = [];

        try {
            $emails = $this->woo->table('wc_orders')
                ->whereNotNull('billing_email')
                ->where('billing_email', '<>', '')
                ->pluck('billing_email', 'id');
        } catch (\Throwable $e) {
            return [];
        }

        // Try to enrich with billing names from the HPOS addresses table.
        $addresses = [];

        try {
            $addresses = $this->woo->table('wc_order_addresses')
                ->where('address_type', 'billing')
                ->get(['order_id', 'first_name', 'last_name', 'phone'])
                ->keyBy('order_id')
                ->all();
        } catch (\Throwable $e) {
            // Addresses table unavailable — names stay empty.
        }

        foreach ($emails as $orderId => $email) {
            $email = strtolower(trim((string) $email));

            if ($email === '' || isset($contacts[$email])) {
                continue;
            }

            $address = $addresses[$orderId] ?? null;

            $contacts[$email] = [
                'first_name' => $address->first_name ?? '',
                'last_name' => $address->last_name ?? '',
                'phone' => $address->phone ?? null,
            ];
        }

        return $contacts;
    }
}
