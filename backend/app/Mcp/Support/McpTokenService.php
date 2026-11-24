<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Data\Mcp\GeneratedMcpToken;
use App\Models\McpToken;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class McpTokenService
{
    private const PREFIX = 'spa_mcp_';

    private const BASE62_CHARS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const RANDOM_LENGTH = 43;

    private const PREFIX_STORED_LENGTH = 16;

    public function createToken(
        Shop $shop,
        string $name,
        string $staffRef,
        ?CarbonImmutable $expiresAt = null,
    ): GeneratedMcpToken {
        $plaintext = self::PREFIX.$this->generateBase62String(self::RANDOM_LENGTH);
        $tokenPrefix = substr($plaintext, 0, self::PREFIX_STORED_LENGTH);
        $tokenHash = $this->hash($plaintext);

        $token = McpToken::create(
            [
                'shop_id' => $shop->id,
                'name' => $name,
                'token_prefix' => $tokenPrefix,
                'token_hash' => $tokenHash,
                'created_by_staff_ref' => $staffRef,
                'expires_at' => $expiresAt,
            ]
        );

        return new GeneratedMcpToken(
            plaintextToken: $plaintext,
            token: $token,
        );
    }

    public function findValidToken(string $plaintextToken): ?McpToken
    {
        $hash = $this->hash($plaintextToken);

        /** @var McpToken|null $token */
        $token = McpToken::query()
            ->active()
            ->where('token_hash', $hash)
            ->first();

        return $token;
    }

    public function revokeToken(Shop $shop, string $tokenId): bool
    {
        /** @var McpToken|null $token */
        $token = McpToken::query()
            ->where('shop_id', $shop->id)
            ->where('id', $tokenId)
            ->whereNull('revoked_at')
            ->first();

        if ($token === null) {
            return false;
        }

        $token->revoke();
        $this->evictTokenCache($token->token_hash);

        return true;
    }

    public function evictTokenCache(string $tokenHash): void
    {
        Cache::store('redis')->forget('mcp:principal:'.$tokenHash);
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    private function generateBase62String(int $length): string
    {
        $result = '';
        $maxIndex = strlen(self::BASE62_CHARS) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= self::BASE62_CHARS[random_int(0, $maxIndex)];
        }

        return $result;
    }
}
