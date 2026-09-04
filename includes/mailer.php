<?php
/**
 * Zero-dependency raw socket SMTP mailer supporting TLS/SSL (e.g., Gmail).
 */
function sendSmtpMail($to, $subject, $messageHtml) {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    
    if (empty($user) || empty($pass)) {
        // Fallback: If SMTP credentials are missing, try standard PHP mail()
        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=utf-8",
            "From: " . SITE_NAME . " <noreply@localhost>",
        ];
        return @mail($to, $subject, $messageHtml, implode("\r\n", $headers));
    }
    
    $headers = [
        "MIME-Version: 1.0",
        "Content-type: text/html; charset=utf-8",
        "From: " . SITE_NAME . " <" . $user . ">",
        "To: <" . $to . ">",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?="
    ];
    
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    
    $socketHost = ($port === 465) ? "ssl://" . $host : $host;
    $socket = @stream_socket_client($socketHost . ":" . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    
    if (!$socket) {
        return false;
    }
    
    $getResponse = function($socket) {
        $data = "";
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === " ") break;
        }
        return $data;
    };
    
    $getResponse($socket);
    
    // EHLO
    fwrite($socket, "EHLO localhost\r\n");
    $getResponse($socket);
    
    // TLS Upgrade (for Port 587)
    if ($port === 587) {
        fwrite($socket, "STARTTLS\r\n");
        $getResponse($socket);
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            fclose($socket);
            return false;
        }
        fwrite($socket, "EHLO localhost\r\n");
        $getResponse($socket);
    }
    
    // Authentication
    fwrite($socket, "AUTH LOGIN\r\n");
    $getResponse($socket);
    
    fwrite($socket, base64_encode($user) . "\r\n");
    $getResponse($socket);
    
    fwrite($socket, base64_encode($pass) . "\r\n");
    $authResp = $getResponse($socket);
    if (strpos($authResp, "235") === false) {
        fclose($socket);
        return false;
    }
    
    // MAIL FROM
    fwrite($socket, "MAIL FROM: <" . $user . ">\r\n");
    $getResponse($socket);
    
    // RCPT TO
    fwrite($socket, "RCPT TO: <" . $to . ">\r\n");
    $getResponse($socket);
    
    // DATA
    fwrite($socket, "DATA\r\n");
    $getResponse($socket);
    
    // Headers and Content
    $content = implode("\r\n", $headers) . "\r\n\r\n" . $messageHtml . "\r\n.\r\n";
    fwrite($socket, $content);
    $dataResp = $getResponse($socket);
    
    // QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    
    return (strpos($dataResp, "250") !== false);
}
