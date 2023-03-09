<?php
/**
 * Ebizmarts_Mandrill Magento JS component
 *
 * @category    Ebizmarts
 * @package     Ebizmarts_Mandrill
 * @author      Ebizmarts Team <info@ebizmarts.com>
 * @copyright   Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Ebizmarts\Mandrill\Model;

use Ebizmarts\Mandrill\Helper\Data;
use Ebizmarts\Mandrill\Model\Api\Mandrill;
use Laminas\Mail\Address\AddressInterface;
use Laminas\Mime\Mime;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessage;

class Transport implements \Magento\Framework\Mail\TransportInterface
{

    private EmailMessage $message;

    private Mandrill $api;

    private Data $helper;

    public function __construct(
         EmailMessage $message,
         Mandrill     $api,
         Data         $helper
    ) {
        $this->message = $message;
        $this->api = $api;
        $this->helper = $helper;
    }

    /**
     * @return \Mandrill
     */
    private function getMandrillApiInstance()
    {
        return $this->api->getApi();
    }

    /**
     * Get message
     *
     * @return EmailMessage
     * @since 100.2.0
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @throws MailException
     */
    public function getMessageDataArray(): array
    {
        if (empty($this->message->getFrom())) {
            throw new MailException(__('No sender email address specified'));
        }

        /** @var AddressInterface $from */
        $from = current($this->message->getFrom());

        $message = [
            'subject' => $this->message->getSubject(),
            'from_name' => $from->getName(),
            'from_email' => $from->getEmail(),
        ];

        $message['to'] = array_map(function ($x) {
            return [
                'email' => $x->getEmail(),
                'name' => $x->getName(),
            ];
        }, $this->message->getTo() ?? []);

        foreach ($this->message->getBcc() as $bcc) {
            $message['to'][] = [
                'email' => $bcc->getEmail(),
                'name' => $bcc->getName(),
                'type' => 'bcc',
            ];
        }

        if ($headers = $this->message->getHeaders()) {
            $message['headers'] = $headers;
        }

        $parts = $this->message->getMessageBody()->getParts();
        foreach ($parts as $part) {
            switch ($part->getType()) {
                case Mime::TYPE_HTML:
                    $message['html'] = $part->getRawContent();
                    break;
                case Mime::TYPE_TEXT:
                    $message['text'] = $part->getRawContent();
                    break;
                default:
                    $message['attachments'][] = [
                        'type' => $part->getType(),
                        'name' => $part->getFileName(),
                        'content' => base64_encode($part->getRawContent()),
                    ];
            }
        }

        return $message;
    }

    /**
     * @throws MailException
     */
    public function processApiCallResult($result)
    {
        $currentResult = current($result);
        if (array_key_exists('status', $currentResult) && $currentResult['status'] === 'rejected') {
            throw new MailException(__("Email sending rejected: %1", [$currentResult['reject_reason']]));
        }
    }

    /**
     * @return void
     *
     * @throws MailException
     */
    public function sendMessage()
    {
        if (!$this->helper->isMandrillEnabled()) {
            return;
        }

        try {
            $mandrillApiInstance = $this->getMandrillApiInstance();
            if ($mandrillApiInstance === null) {
                return;
            }

            $request = $this->getMessageDataArray();

            $result = $mandrillApiInstance->messages->send($request);
            $this->processApiCallResult($result);
        } catch (MailException $e) {
            $this->helper->log($e->getMessage());
            // throw $e;
        } catch (\Exception $e) {
            $this->helper->log($e->getMessage());
            throw new MailException(__('Unable to send mail. Please try again later'));
        }
    }
}
