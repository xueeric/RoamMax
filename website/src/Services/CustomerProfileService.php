<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class CustomerProfileService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return array<string, mixed> */
    public function getProfile(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.email, u.name, u.phone, u.role,
                    c.company_name, c.home_address_json, c.shipping_address_json, c.billing_address_json
             FROM users u
             LEFT JOIN customers c ON c.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new BookingUnavailableException('Account not found.');
        }

        return [
            'user_id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'phone' => (string) ($row['phone'] ?? ''),
            'role' => (string) $row['role'],
            'company_name' => (string) ($row['company_name'] ?? ''),
            'home_address' => AddressService::decode($row['home_address_json'] ?? null),
            'shipping_address' => AddressService::decode($row['shipping_address_json'] ?? null),
            'billing_address' => AddressService::decode($row['billing_address_json'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateProfile(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $companyName = trim((string) ($data['company_name'] ?? ''));

        if ($name === '') {
            throw new BookingUnavailableException('Name is required.');
        }

        $homeAddress = $this->enrichAddressWithCoordinates($this->parseAddressInput($data, 'home_'));
        AddressService::validate($homeAddress, ['line1', 'city', 'province', 'postal_code'], 'home');

        $shippingAddress = $this->parseAddressInput($data, 'shipping_');
        if ($this->hasAnyAddressInput($data, 'shipping_')) {
            $shippingAddress = $this->enrichAddressWithCoordinates($shippingAddress);
            AddressService::validate($shippingAddress, ['line1', 'city', 'province', 'postal_code'], 'shipping');
        } else {
            $shippingAddress = null;
        }

        $billingAddress = $this->parseAddressInput($data, 'billing_');
        AddressService::validate($billingAddress, ['line1', 'city', 'province', 'postal_code'], 'billing');

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE users SET name = :name, phone = :phone WHERE id = :id AND role = :role'
            )->execute([
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'id' => $userId,
                'role' => 'customer',
            ]);

            $exists = $this->db->prepare('SELECT user_id FROM customers WHERE user_id = :user_id LIMIT 1');
            $exists->execute(['user_id' => $userId]);
            if (!$exists->fetch()) {
                $this->db->prepare('INSERT INTO customers (user_id) VALUES (:user_id)')->execute(['user_id' => $userId]);
            }

            $this->db->prepare(
                'UPDATE customers
                 SET company_name = :company_name,
                     home_address_json = :home_address_json,
                     shipping_address_json = :shipping_address_json,
                     billing_address_json = :billing_address_json
                 WHERE user_id = :user_id'
            )->execute([
                'company_name' => $companyName !== '' ? $companyName : null,
                'home_address_json' => AddressService::encode($homeAddress),
                'shipping_address_json' => AddressService::encode($shippingAddress),
                'billing_address_json' => AddressService::encode($billingAddress),
                'user_id' => $userId,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getProfile($userId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveFromCheckout(int $userId, array $data): array
    {
        return $this->updateProfile($userId, $data);
    }

    public function shippingProvinceForQuote(int $userId): ?string
    {
        try {
            $profile = $this->getProfile($userId);
        } catch (BookingUnavailableException) {
            return null;
        }

        $province = $profile['shipping_address']['province'] ?? null;

        return is_string($province) && $province !== '' ? $province : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function parseAddressInput(array $input, string $prefix): array
    {
        return AddressService::normalize([
            'name' => $input[$prefix . 'name'] ?? '',
            'line1' => $input[$prefix . 'line1'] ?? '',
            'line2' => $input[$prefix . 'line2'] ?? '',
            'city' => $input[$prefix . 'city'] ?? '',
            'province' => $input[$prefix . 'province'] ?? '',
            'postal_code' => $input[$prefix . 'postal_code'] ?? '',
            'country' => $input[$prefix . 'country'] ?? 'CA',
        ]);
    }

    /** @param array<string, mixed> $input */
    private function hasAnyAddressInput(array $input, string $prefix): bool
    {
        foreach (['name', 'line1', 'line2', 'city', 'province', 'postal_code'] as $field) {
            if (trim((string) ($input[$prefix . $field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{latitude: float, longitude: float} $coordinates
     */
    public function saveAddressCoordinates(int $userId, string $field, array $coordinates): void
    {
        if (!in_array($field, ['home', 'shipping'], true)) {
            return;
        }

        $column = $field === 'home' ? 'home_address_json' : 'shipping_address_json';
        $stmt = $this->db->prepare("SELECT {$column} FROM customers WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $address = AddressService::decode($row[$column] ?? null);
        if ($address === null) {
            return;
        }

        $address['latitude'] = (string) $coordinates['latitude'];
        $address['longitude'] = (string) $coordinates['longitude'];

        $this->db->prepare(
            "UPDATE customers SET {$column} = :address_json WHERE user_id = :user_id"
        )->execute([
            'address_json' => AddressService::encode($address),
            'user_id' => $userId,
        ]);
    }

    /** @param array<string, string> $address */
    private function enrichAddressWithCoordinates(array $address): array
    {
        if ($address === [] || (new GeocodingService())->coordinatesFromAddress($address) !== null) {
            return $address;
        }

        $coords = (new GeocodingService())->geocodeAddress($address);
        if ($coords === null) {
            return $address;
        }

        return array_merge($address, [
            'latitude' => (string) $coords['latitude'],
            'longitude' => (string) $coords['longitude'],
        ]);
    }
}
