<?php
defined( 'ABSPATH' ) || exit;

class RHCM_Paysuite {

    private string $clientCode;
    private string $apiKey;
    private string $baseUrl;

    public function __construct() {
        $this->clientCode = get_option( 'rhcm_paysuite_client_code', '' );
        $this->apiKey     = get_option( 'rhcm_paysuite_api_key',     '' );
        $this->baseUrl    = get_option( 'rhcm_paysuite_sandbox', 0 )
            ? 'https://playpen.accesspaysuite.com/api/v3'
            : 'https://ddcms.accesspaysuite.com/api/v3';
    }

    /** True only when the feature is enabled AND credentials are configured. */
    public static function is_enabled(): bool {
        return (bool) get_option( 'rhcm_paysuite_enabled', 0 )
            && get_option( 'rhcm_paysuite_client_code', '' ) !== ''
            && get_option( 'rhcm_paysuite_api_key', '' ) !== '';
    }

    // ── Core HTTP ──────────────────────────────────────────────────────────────

    private function url( string $path ): string {
        return $this->baseUrl . '/client/' . rawurlencode( $this->clientCode ) . '/' . ltrim( $path, '/' );
    }

    private function request( string $method, string $path, array $data = [] ): array {
        $ch = curl_init( $this->url( $path ) );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
            CURLOPT_HTTPHEADER     => [
                'apiKey: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ] );
        if ( ! empty( $data ) ) {
            curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $data ) );
        }
        $body    = curl_exec( $ch );
        $status  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curlErr = curl_error( $ch );
        curl_close( $ch );

        if ( $curlErr ) {
            throw new \RuntimeException( 'Paysuite connection error: ' . $curlErr );
        }
        $decoded = json_decode( $body, true ) ?? [];
        if ( $status >= 400 ) {
            $msg    = $decoded['Message'] ?? $decoded['message'] ?? $decoded['error'] ?? "HTTP $status";
            $errors = $decoded['errors'] ?? $decoded['Errors'] ?? $decoded['validationErrors'] ?? null;
            $detail = $errors ? ' — ' . wp_json_encode( $errors ) : ' (raw: ' . substr( $body, 0, 300 ) . ')';
            throw new \RuntimeException( 'Paysuite: ' . $msg . $detail );
        }
        return $decoded;
    }

    // ── API methods ────────────────────────────────────────────────────────────

    public function createCustomer( array $payload ): array {
        return $this->request( 'POST', 'customer', $payload );
    }

    public function createContract( string $customerUuid, array $payload ): array {
        return $this->request( 'POST', 'customer/' . rawurlencode( $customerUuid ) . '/contract', $payload );
    }

    // ── Payload builders ──────────────────────────────────────────────────────

    public static function makeCustomerRef( int $appId ): string {
        return 'QMAPP' . str_pad( $appId, 5, '0', STR_PAD_LEFT );
    }

    public static function buildCustomerPayload( int $appId, array $f ): array {
        $holderName = substr( trim( preg_replace( '/[^A-Za-z0-9 ]/', '', $f['account_holder'] ) ), 0, 18 );
        return [
            'Email'             => $f['email'],
            'Title'             => $f['title'] ?? '',
            'CustomerRef'       => self::makeCustomerRef( $appId ),
            'FirstName'         => $f['first_name'],
            'Surname'           => $f['last_name'],
            'Line1'             => $f['address_line1'],
            'Line2'             => $f['address_line2'] ?? '',
            'PostCode'          => strtoupper( trim( $f['postcode'] ) ),
            'AccountNumber'     => preg_replace( '/\D/', '', $f['account_number'] ),
            'BankSortCode'      => preg_replace( '/\D/', '', $f['sort_code'] ),
            'AccountHolderName' => $holderName,
        ];
    }

    /**
     * Monthly DD collecting on the 21st.
     * startDate is the 21st of this month, or next month if today is on/past the 21st.
     */
    public static function buildContractPayload( string $membershipName, float $annualPrice ): array {
        $monthly = round( $annualPrice / 12, 2 );
        $now = new \DateTime( 'today' );
        if ( (int) $now->format( 'j' ) >= 21 ) {
            $now->modify( 'first day of next month' );
        }
        $startDate = $now->format( 'Y-m-' ) . '21T00:00:00.000';
        return [
            'startDate'           => $startDate,
            'scheduleName'        => 'Monthly, every 1',
            'every'               => 1,
            'paymentDayInMonth'   => 21,
            'amount'              => $monthly,
            'terminationType'     => 'Until further notice',
            'isGiftAid'           => false,
            'additionalReference' => $membershipName,
        ];
    }

    /** Strip currency symbols and non-numeric chars to get a float annual price. */
    public static function parsePrice( string $raw ): float {
        $cleaned = preg_replace( '/[^0-9.]/', '', $raw );
        return $cleaned !== '' ? (float) $cleaned : 0.0;
    }
}
