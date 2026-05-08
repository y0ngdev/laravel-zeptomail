<?php

namespace ZohoMail\LaravelZeptoMail\Transport;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use function json_decode;

class ZeptoMailTransport implements TransportInterface
{
    protected string $apikey;
    protected string $host;
    protected HttpClient $client;

    public function __construct(string $apikey, string $host)
    {
        $this->apikey = $apikey;
        $this->host = $host;
        $this->client = new HttpClient();
    }

    public function send(RawMessage $message, Envelope $envelope = null): ?SentMessage
    {
        try {
            $urlToSend = $this->getEndpoint();
            $email = MessageConverter::toEmail($message);
            $data = $this->getPayload($email, $envelope);
            $data["from"] = $this->getFrom($message);
            $response = $this->client->post($urlToSend, [
                'headers' => [
                    'Authorization' =>  $this->apikey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'user-agent' => 'Laravel'
                ],
                'json' => $data
            ]);
        } catch (ConnectException $e) {
            Log::error('Connection error: ' . $e->getMessage());
            throw new \RuntimeException('Failed to connect to mail server.', 0, $e);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $errorBody = $e->getResponse()->getBody()->getContents();
                Log::error("Mail API error: HTTP $statusCode", ['response' => $errorBody]);
                throw new \RuntimeException("Mail API error: $errorBody", 0, $e);
            } else {
                Log::error('Request error: ' . $e->getMessage());
                throw new \RuntimeException('Mail request failed.', 0, $e);
            }
        } catch (\Throwable $e) {
            Log::error('Unexpected error: ' . $e->getMessage());
            throw new \RuntimeException('An unexpected error occurred while sending mail.', 0, $e);
        }


        return new SentMessage($message, $envelope);
    }

    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        // No plugins needed
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
    protected function getFrom(RawMessage $message): array
    {
        $from = $message->getFrom();

        if (count($from) > 0) {
            return ['name' => $from[0]->getName(), 'address' => $from[0]->getAddress()];
        }

        return ['email' => '', 'name' => ''];
    }
    /**
     * @return string|null
     */
    private function getEndpoint(): ?string
    {

        return "https://zeptomail." . $this->domainMapping[$this->host] . '/v1.1/email';
    }
    /**
     * @param Email $email
     * @param Envelope $envelope
     * @return array
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        $recipients = $this->getRecipients($email, $envelope);
        $toaddress = $this->getEmailDetailsByType($recipients, 'to');
        $ccaddress = $this->getEmailDetailsByType($recipients, 'cc');
        $bccaddress = $this->getEmailDetailsByType($recipients, 'bcc');
        $attachmentJSONArr = array();
        $payload = [

            'subject' => $email->getSubject()
        ];
        if ($email->getHtmlBody() != null) {
            $payload['htmlbody'] = $email->getHtmlBody();
        } else {
            $payload['htmlbody'] = $email->getTextBody();
        }


        if (isset($toaddress) && !empty($toaddress)) {
            $payload['to'] = $toaddress;
        }
        if (isset($ccaddress) && !empty($ccaddress)) {
            $payload['cc'] = $ccaddress;
        }
        if (isset($bccaddress) && !empty($bccaddress)) {
            $payload['bcc'] = $bccaddress;
        }

        $replyToHeader = $email->getHeaders()->get('Reply-To');
        if ($replyToHeader) {
            $replyToAddresses = $replyToHeader->getAddresses();
            if (!empty($replyToAddresses)) {
                $firstReplyTo = $replyToAddresses[0];
                $payload['reply_to'] = [
                    'address' => $firstReplyTo->getAddress(),
                    'name' => $firstReplyTo->getName() ?? '',
                ];
            }
        }

        foreach ($email->getAttachments() as $attachment) {

            $headers = $attachment->getPreparedHeaders();
            $disposition = $headers->getHeaderBody('Content-Disposition');
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');

            $att = [
                'content' => base64_encode($attachment->getBody()),
                'name' => $filename,
                'mime_type' => $headers->get('Content-Type')->getBody()
            ];

            if ($name = $headers->getHeaderParameter('Content-Disposition', 'name')) {
                $att['name'] = $name;
            }

            $attachmentJSONArr[] = $att;
        }

        if (isset($attachmentJSONArr) && !empty($attachmentJSONArr)) {
            $payload['attachments'] = $attachmentJSONArr;
        }


        return $payload;
    }
    /**
     * @param Email $email
     * @param Envelope $envelope
     * @return array
     */
    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        $recipients = [];

        foreach ($envelope->getRecipients() as $recipient) {
            $type = 'to';

            if (\in_array($recipient, $email->getBcc(), true)) {
                $type = 'bcc';
            } elseif (\in_array($recipient, $email->getCc(), true)) {
                $type = 'cc';
            }

            $recipientPayload = [
                'email' => $recipient->getAddress(),
                'type' => $type,
            ];

            if ('' !== $recipient->getName()) {
                $recipientPayload['name'] = $recipient->getName();
            }

            $recipients[] = $recipientPayload;
        }

        return $recipients;
    }
    protected function getEmailDetailsByType(array $recipients, string $type): array
    {
        $sendmailaddress = [];
        foreach ($recipients as $recipient) {
            if ($type === $recipient['type']) {
                $emailDetail = [
                    'address' => $recipient['email']
                ];
                if (isset($recipient['name'])) {
                    $emailDetail['name'] = $recipient['name'];
                }
                $emailDetails = ['email_address' => $emailDetail];
                $sendmailaddress[] = $emailDetails;
            }
        }
        return $sendmailaddress;
    }

    public $domainMapping = [
        "zoho.com"          => "zoho.com",
        "zoho.eu"           => "zoho.eu",
        "zoho.in"           => "zoho.in",
        "zoho.com.cn"       => "zoho.com.cn",
        "zoho.com.au"       => "zoho.com.au",
        "zoho.jp"           => "zoho.jp",
        "zohocloud.ca"      => "zohocloud.ca",
        "zoho.sa"           => "zoho.sa"
    ];
}
