<?php

namespace Chocoholics\LaravelElasticEmail;

use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ElasticTransport extends Transport
{

    /**
     * Guzzle client instance.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The Elastic Email API key.
     *
     * @var string
     */
    protected $key;

    /**
     * The Elastic Email username.
     *
     * @var string
     */
    protected $account;

    /**
     * THe Elastic Email API end-point.
     *
     * @var string
     */
    protected $url = 'https://api.elasticemail.com/v2/email/send';

    /**
     * Create a new Elastic Email transport instance.
     *
     * @param  \GuzzleHttp\ClientInterface  $client
     * @param  string  $key
     * @param  string  $username
     *
     * @return void
     */
    public function __construct(ClientInterface $client, $key, $account, $model, $rate, $transactional)
    {
        $this->client = $client;
        $this->key = $key;
        $this->account = $account;
        $this->rate    = $rate;
        $this->model   =  $model;
        $this->transactional =  $transactional;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        $data = [
            'api_key' => $this->key,
            'account' => $this->account,
            'msgTo' => $this->getEmailAddresses($message),
            'msgCC' => $this->getEmailAddresses($message, 'getCc'),
            'msgBcc' => $this->getEmailAddresses($message, 'getBcc'),
            'msgFrom' => $this->getFromAddress($message)['email'],
            'msgFromName' => $this->getFromAddress($message)['name'],
            'from' => $this->getFromAddress($message)['email'],
            'fromName' => $this->getFromAddress($message)['name'],
            'to' => $this->getEmailAddresses($message),
            'subject' => $message->getSubject(),
            'body_html' => $message->getBody(),
            'body_text'       => $this->getText($message),
            'isTransactional' => $this->transactional,
            'files'           => $this->files($message->getChildren())
        ];

        $a = $data;
        unset($a['body_html']);

        $model = new $this->model();
        $model->data= json_encode($data);
        return $model->save();
    }

    /**
     * Get the plain text part.
     *
     * @param  \Swift_Mime_SimpleMessage $message
     * @return text|null
     */
    protected function getText(Swift_Mime_SimpleMessage $message)
    {
        $text = null;

        foreach ($message->getChildren() as $child) {
            if ($child->getContentType() == 'text/plain') {
                $text = $child->getBody();
            }
        }

        if ($text == null) {
            $text = strip_tags($message->getBody());
        }

        return $text;
    }

    /**
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return array
     */
    protected function getFromAddress(Swift_Mime_SimpleMessage $message)
    {
        return [
            'email' => array_keys($message->getFrom())[0],
            'name' => array_values($message->getFrom())[0],
        ];
    }

    protected function getEmailAddresses(Swift_Mime_SimpleMessage $message, $method = 'getTo')
    {
        $data = call_user_func([$message, $method]);

        if (is_array($data)) {
            return implode(',', array_keys($data));
        }
        return '';
    }

    /**
     * Check Swift_Attachment count
     * @param $attachments
     * @return bool
    */
    public function files($attachments)
    {
        //solo attachement
        $files = array_filter($attachments, function ($e) {
            return $e instanceof \Swift_Attachment && $e->getDisposition() == 'attachment';
        });

        if (empty($files)) {
            return null;
        }

        $data = [];
        $i = 1;
        foreach ($files as $attachment) {
            $attachedFile = $attachment->getBody();
            $fileName = $attachment->getFilename();
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $tempName = uniqid() . '.' . $ext;
            Storage::put($tempName, $attachedFile);
            $type = $attachment->getContentType();
            $attachedFilePath = storage_path('app/' . $tempName);
            $data[] = [
                'name'     => "file_{$i}",
                'contents' => $attachedFilePath,
                'filename' =>  $fileName,
            ];
            $i++;
        }

        return $data;
    }

    public function attachmentParam(array $data)
    {
        $p = array_map(function ($i) {
            $i['contents'] = fopen($i['contents'], 'r');
            return $i;
        }, $data['files']);

        unset($data['files']);

        foreach ($data as $key => $value) {
            $p[] = [
                'name'     => $key,
                'contents' => $value,
            ];
        }

        return [
            'multipart' =>  $p
        ];
    }

    public function withoutAttachment(array $data)
    {
        unset($data['files']);
        return [
            'form_params' => $data
        ];
    }

    public function sendMail(array $data, $resend = true)
    {
        $params = $data['files'] ?
            $this->attachmentParam($data) :
            $this->withoutAttachment($data);

        $result = $this->client->post($this->url, $params);
        $body = $result->getBody();
        $obj  = json_decode($body->getContents());
        if (empty($obj->success)) {
            Log::warning($obj->error);
            //intenta reenviar sin adjunto
            if ($data['files'] && $resend) {
                $data['files'] =  null;
                $this->sendMail($data, false);
            }
        } else {
            return true;
        }
    }

    /**
     * Process the queue
     * @return [type] [description]
     */
    public function sendQueue()
    {
        $model = $this->model;
        $emails = $model::whereNull('send_at')
            ->orderBy('created_at', 'asc')
            ->take($this->rate)
            ->get();

        //delete old
        $model::where('send_at', '<', date("Y-m-d H:i:s", strtotime("-1 day")))->delete();

        foreach ($emails as $e) {
            try {
                $data = $e->data;
                if ($this->sendMail($data)) {
                    $e->send_at = date("Y-m-d H:i:s");
                    $e->save();
                };
            } catch (Exception $e) {
                Log::error($e);
                break;
            }
        }
    }
}
