<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace crmeb\services\mail;

use crmeb\exceptions\ApiException;

/**
 * 最小構成の SMTP クライアント
 *
 * 外部ライブラリを追加せずに送信するため、PHP コアの stream_socket_client と
 * openssl 拡張のみで実装しています。STARTTLS / SMTPS、AUTH LOGIN / PLAIN、
 * UTF-8 の件名（RFC 2047）と本文に対応します。
 *
 * Class SmtpMailer
 * @package crmeb\services\mail
 */
class SmtpMailer
{
    /** @var resource|null */
    protected $socket;

    /** @var array */
    protected $config;

    /** @var string 直近のサーバー応答（デバッグ用） */
    protected $lastResponse = '';

    /** @var string EOL は RFC 5321 で CRLF 固定 */
    const EOL = "\r\n";

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * メールを送信する
     *
     * @param string $to 宛先アドレス
     * @param string $subject 件名
     * @param string $body 本文（text/plain, UTF-8）
     * @return bool
     */
    public function send(string $to, string $subject, string $body): bool
    {
        $this->assertConfigured();
        if (!self::isValidAddress($to)) {
            throw new ApiException('メールアドレスの形式が正しくありません');
        }

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->transmit($to, $subject, $body);
            $this->command('QUIT', [221]);
            return true;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * 設定が揃っているか
     */
    protected function assertConfigured(): void
    {
        foreach (['host', 'from_address'] as $key) {
            if (empty($this->config[$key])) {
                throw new ApiException('メール送信の設定が未完了です');
            }
        }
    }

    /**
     * ソケット接続（SMTPS の場合はここから TLS）
     */
    protected function connect(): void
    {
        $encryption = strtolower((string)($this->config['encryption'] ?? ''));
        $host = $this->config['host'];
        $port = (int)($this->config['port'] ?? 587);
        $timeout = (int)($this->config['timeout'] ?? 15);

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => !$this->allowSelfSigned(),
                'verify_peer_name' => !$this->allowSelfSigned(),
                'allow_self_signed' => $this->allowSelfSigned(),
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errNo = 0;
        $errStr = '';
        $this->socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errNo,
            $errStr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$this->socket) {
            throw new ApiException('メールサーバーに接続できません: ' . ($errStr ?: 'error ' . $errNo));
        }
        stream_set_timeout($this->socket, $timeout);

        // 接続時の挨拶（220）
        $this->expect([220]);
    }

    protected function allowSelfSigned(): bool
    {
        return (bool)($this->config['allow_self_signed'] ?? false);
    }

    /**
     * EHLO と、必要なら STARTTLS
     */
    protected function handshake(): void
    {
        $hostname = $this->clientHostname();
        $this->command('EHLO ' . $hostname, [250]);

        if (strtolower((string)($this->config['encryption'] ?? '')) === 'tls') {
            $this->command('STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
                throw new ApiException('メールサーバーとの TLS 接続に失敗しました');
            }
            // TLS 確立後は EHLO をやり直す（RFC 3207）
            $this->command('EHLO ' . $hostname, [250]);
        }
    }

    /**
     * EHLO で名乗るホスト名
     */
    protected function clientHostname(): string
    {
        $from = (string)$this->config['from_address'];
        $domain = substr($from, strpos($from, '@') + 1);
        return $domain !== '' ? $domain : 'localhost';
    }

    /**
     * 認証（username が空なら省略）
     */
    protected function authenticate(): void
    {
        $username = (string)($this->config['username'] ?? '');
        $password = (string)($this->config['password'] ?? '');
        if ($username === '') {
            return;
        }
        // AUTH LOGIN が最も広く実装されているため優先する
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($username), [334]);
        $this->command(base64_encode($password), [235]);
    }

    /**
     * 封筒と本文の送信
     */
    protected function transmit(string $to, string $subject, string $body): void
    {
        $from = (string)$this->config['from_address'];
        $this->command('MAIL FROM:<' . $from . '>', [250]);
        $this->command('RCPT TO:<' . $to . '>', [250, 251]);
        $this->command('DATA', [354]);
        $this->write($this->buildMessage($to, $subject, $body) . self::EOL . '.' . self::EOL);
        $this->expect([250]);
    }

    /**
     * ヘッダーと本文を組み立てる
     */
    protected function buildMessage(string $to, string $subject, string $body): string
    {
        $from = (string)$this->config['from_address'];
        $fromName = (string)($this->config['from_name'] ?? '');

        $headers = [];
        $headers[] = 'From: ' . ($fromName !== ''
            ? $this->encodeHeader($fromName) . ' <' . $from . '>'
            : $from);
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->clientHostname() . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        // 本文は base8bit を避けて base64 にし、76 文字で折り返す
        $encodedBody = rtrim(chunk_split(base64_encode($body), 76, self::EOL));

        return implode(self::EOL, $headers) . self::EOL . self::EOL . $encodedBody;
    }

    /**
     * 非 ASCII を含むヘッダーを RFC 2047 で符号化する
     */
    protected function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $value)) {
            // 引用符が必要なのは表示名のみだが、素の ASCII はそのまま返す
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * コマンド送信と応答確認
     *
     * @param string $command
     * @param int[] $expected 期待するステータスコード
     */
    protected function command(string $command, array $expected): string
    {
        $this->write($command . self::EOL);
        return $this->expect($expected);
    }

    protected function write(string $data): void
    {
        if (!$this->socket) {
            throw new ApiException('メールサーバーとの接続が切断されています');
        }
        if (@fwrite($this->socket, $data) === false) {
            throw new ApiException('メールサーバーへの送信に失敗しました');
        }
    }

    /**
     * 応答を読み、期待するコードか確認する
     *
     * @param int[] $expected
     * @return string
     */
    protected function expect(array $expected): string
    {
        $response = $this->readResponse();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new ApiException('メール送信に失敗しました（SMTP ' . $code . '）');
        }
        return $response;
    }

    /**
     * 複数行応答（250-...）をまとめて読む
     */
    protected function readResponse(): string
    {
        $lines = [];
        while (true) {
            $line = @fgets($this->socket, 1024);
            if ($line === false || $line === '') {
                $meta = $this->socket ? stream_get_meta_data($this->socket) : ['timed_out' => false];
                throw new ApiException(!empty($meta['timed_out'])
                    ? 'メールサーバーの応答がタイムアウトしました'
                    : 'メールサーバーから応答がありません');
            }
            $lines[] = rtrim($line, "\r\n");
            // 4 文字目が '-' なら継続行
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $this->lastResponse = implode("\n", $lines);
        return $this->lastResponse;
    }

    protected function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * 直近のサーバー応答（ログ用）
     */
    public function getLastResponse(): string
    {
        return $this->lastResponse;
    }

    /**
     * メールアドレスの形式検証
     *
     * @param string $address
     * @return bool
     */
    public static function isValidAddress(string $address): bool
    {
        $address = trim($address);
        if ($address === '' || strlen($address) > 100) {
            return false;
        }
        // ヘッダーインジェクション対策として改行を含むものは拒否する
        if (preg_match('/[\r\n]/', $address)) {
            return false;
        }
        return (bool)filter_var($address, FILTER_VALIDATE_EMAIL);
    }
}
