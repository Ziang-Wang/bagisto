<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates customers from the legacy store.
 *
 * Pulls every registered customer from WooCommerce's `wc_customer_lookup`
 * table and additionally back-fills any billing e-mail found on orders that
 * has no matching customer record (guest checkouts). Each migrated customer
 * gets a random password and is left unverified so the owner can trigger a
 * password reset later.
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
            $console->warn('  No customers found in the legacy store.');

            return;
        }

        $groupId = (int) config('woo-importer.defaults.customer_group_id', 2);
        $channelId = (int) config('woo-importer.defaults.channel_id', 1);

        $created = $skipped = 0;

        foreach ($contacts as $email => $contact) {
            if ($existing = $this->customerRepository->findOneByField('email', $email)) {
                // Remember the mapping even when the record already exists so
                // the order migrator can link to it.
                $this->mapping->put(Mapping::ENTITY_CUSTOMER, $email, $existing->id);

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
     * Build [email => [first_name, last_name, phone]] from the registered
     * customers table, then back-fill from order billing details.
     *
     * @return array<string, array<string, string|null>>
     */
    protected function collectContacts(): array
    {
        $contacts = [];

        // 1) Registered customers from WooCommerce's analytics lookup table.
        try {
            $rows = $this->woo->table('wc_customer_lookup')
                ->whereNotNull('email')
                ->where('email', '<>', '')
                ->get(['first_name', 'last_name', 'email']);

            foreach ($rows as $row) {
                $email = strtolower(trim((string) $row->email));

                if ($email === '' || isset($contacts[$email])) {
                    continue;
                }

                $contacts[$email] = [
                    'first_name' => (string) $row->first_name,
                    'last_name' => (string) $row->last_name,
                    'phone' => null,
                ];
            }
        } catch (\Throwable $e) {
            // Lookup table unavailable — fall back to order e-mails only.
        }

        // 2) Back-fill from order billing e-mails (covers guest checkouts).
        $this->mergeOrderContacts($contacts);

        return $contacts;
    }

    /**
     * Merge billing contacts taken from the HPOS order tables into $contacts.
     *
     * @param  array<string, array<string, string|null>>  $contacts
     */
    protected function mergeOrderContacts(array &$contacts): void
    {
        try {
            $emails = $this->woo->table('wc_orders')
                ->whereNotNull('billing_email')
                ->where('billing_email', '<>', '')
                ->pluck('billing_email', 'id');
        } catch (\Throwable $e) {
            return;
        }

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
    }
}
