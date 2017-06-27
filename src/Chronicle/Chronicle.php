<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle;

use ParagonIE\Blakechain\Blakechain;
use ParagonIE\Chronicle\Exception\ClientNotFound;
use ParagonIE\ConstantTime\Base64UrlSafe;
use ParagonIE\EasyDB\EasyDB;
use ParagonIE\Sapient\Adapter\Slim;
use ParagonIE\Sapient\CryptographyKeys\SigningPublicKey;
use ParagonIE\Sapient\CryptographyKeys\SigningSecretKey;
use ParagonIE\Sapient\Sapient;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Chronicle
 * @package ParagonIE\Chronicle
 */
class Chronicle
{
    /** @var EasyDB $easyDb */
    protected static $easyDb;

    /** @var SigningSecretKey $signingKey */
    protected static $signingKey;

    /* This constant is the name of the header used to find the
       corresponding public key: */
    const CLIENT_IDENTIFIER_HEADER = 'Chronicle-Client-Key-ID';

    /**
     * @param string $body
     * @param string $signature
     * @param SigningPublickey $publicKey
     * @return array<string, string>
     * @throws \Error
     */
    public static function extendBlakechain(
        string $body,
        string $signature = '',
        SigningPublicKey $publicKey = null
    ): array {
        $db = self::$easyDb;
        $db->beginTransaction();
        $lasthash = $db->row(
            'SELECT currhash, hashstate FROM chronicle_chain ORDER BY id DESC LIMIT 1'
        );

        $blakechain = new Blakechain();
        if (empty($lasthash)) {
            $prevhash = '';
        } else {
            $prevhash = $lasthash['currhash'];
            $blakechain->setFirstPrevHash(
                Base64UrlSafe::decode($lasthash['currhash'])
            );
            $hashstate = Base64UrlSafe::decode($lasthash['hashstate']);
            $blakechain->setSummaryHashState($hashstate);
        }
        $blakechain->appendData($body);
        $fields = [
            'data' => $body,
            'prevhash' => $prevhash,
            'currhash' => $blakechain->getLastHash(),
            'hashstate' => $blakechain->getSummaryHashState(),
            'summaryhash' => $blakechain->getSummaryHash(),
            'created' => (new \DateTime())->format(\DateTime::ATOM)
        ];
        if ($signature && $publicKey) {
            $fields['publickey'] = $publicKey->getString();
            $fields['signature'] = $signature;
        }
        $db->insert('chronicle_chain', $fields);
        if (!$db->commit()) {
            $db->rollBack();
            throw new \Error('Could not commit new hash to database');
        }
        return [
            'currhash' => $fields['currhash'],
            'summaryhash' => $fields['summaryhash'],
            'created' => $fields['created']
        ];
    }

    /**
     * @param ResponseInterface $response
     * @param string $errorMessage
     * @param int $errorCode
     * @return ResponseInterface
     */
    public static function errorResponse(
        ResponseInterface $response,
        string $errorMessage,
        int $errorCode = 400
    ): ResponseInterface {
        return static::getSapient()->createSignedJsonResponse(
            $errorCode,
            [
                'datetime' => (new \DateTime())->format(\DateTime::ATOM),
                'status' => 'ERROR',
                'message' => $errorMessage
            ],
            self::getSigningKey(),
            $response->getHeaders(),
            $response->getProtocolVersion()
        );
    }

    /**
     * @param string $clientId
     * @return SigningPublicKey
     * @throws ClientNotFound
     */
    public static function getClientsPublicKey(string $clientId): SigningPublicKey
    {
        $sqlResult = static::$easyDb->row(
            "SELECT * FROM chronicle_clients WHERE publicid = ?",
            $clientId
        );
        if (empty($sqlResult)) {
            throw new ClientNotFound('Client not found');
        }
        return new SigningPublicKey(
            Base64UrlSafe::decode($sqlResult['publickey'])
        );
    }


    /**
     * @return EasyDB
     */
    public static function getDatabase(): EasyDB
    {
        return self::$easyDb;
    }

    /**
     * @return Sapient
     */
    public static function getSapient(): Sapient
    {
        return new Sapient(new Slim());
    }

    /**
     * @return SigningSecretKey
     * @throws \Error
     */
    public static function getSigningKey(): SigningSecretKey
    {
        if (self::$signingKey) {
            return self::$signingKey;
        }
        $keyFile = \file_get_contents(CHRONICLE_APP_ROOT . '/local/signing-secret.key');
        if (!\is_string($keyFile)) {
            throw new \Error('Could not load key file');
        }
        return new SigningSecretKey(
            Base64UrlSafe::decode($keyFile)
        );
    }

    /**
     * @param EasyDB $db
     * @return EasyDB
     */
    public static function setDatabase(EasyDB $db): EasyDB
    {
        self::$easyDb = $db;
        return self::$easyDb;
    }
}
