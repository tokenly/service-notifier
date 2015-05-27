<?php

namespace App;

use App\Cmd;
use App\Log;
use App\Store;
use Exception;
use Maknz\Slack\Client;
use Mandrill;

/**
* EventHandler
*/
class EventHandler
{


    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new EventHandler();
        }
        return self::$INSTANCE;
    }


    public function handleEvent($event) {
        $service_id = $event['ServiceID'];
        if (!strlen($service_id)) { return; }

        $name = $event['Name'];
        $check_id = $event['CheckID'];
        $status = $event['Status'];
        $is_up = ($status == 'passing');

        Log::debug("$name: ".($is_up?'UP':'DOWN')."");
        $last_state = $this->lastState($check_id);
        $last_state_status = ($last_state ? $last_state->status : null);

        Log::debug("$name: \$last_state = $last_state_status ".($last_state ? $last_state->timestamp : null));

        if ($is_up) {
            if ($last_state_status == 'up') { return; }

            if ($last_state_status !== null) {
                // going up
                $this->handleGoUp($check_id, $event);
            }

            // save state
            $this->changeStatus('up', $check_id, $event);
        } else {
            // down
            if ($last_state_status == 'down') { return; }

            // always handle down events
            $this->handleGoDown($check_id, $event);

            // save state
            $this->changeStatus('down', $check_id, $event);

        }
    }


    protected function lastState($check_id) {
        $state = $this->store->findByID($check_id);
        if ($state === null) { return null; }
        return $state;
    }

    protected function handleGoUp($check_id, $event) {
        print "Now UP: $check_id\n";
        $name = $event['Name'];
        $this->notify('up', $name, $check_id);
    }
    protected function handleGoDown($check_id, $event) {
        print "Now DOWN: $check_id\n";
        $name = $event['Name'];
        $note = ltrim($event['Notes']."\n".$event['Output']);
        $this->notify('down', $name, $check_id, $note);
    }

    protected function changeStatus($status, $check_id, $event) {
        $state = $this->store->findOrCreateState($check_id);
        $state->status    = $status;
        $state->timestamp = time();
        $this->store->storeState($state);
    }

    public function notify($status, $name, $check_id, $note=null) {
        $should_email = !!getenv('EMAIL_NOTIFICATIONS') AND getenv('EMAIL_NOTIFICATIONS') != 'false';
        if ($should_email) {
            if ($status == 'up') {
                $this->email("Service UP: $name", "Service $name is now UP.\n\n".date("Y-m-d H:i:s"), $this->buildEmailRecipients($check_id));
            }
            if ($status == 'down') {
                $this->email("Service DOWN: $name", "Service $name is now DOWN.\n\n".($note?$note."\n\n":'').date("Y-m-d H:i:s"), $this->buildEmailRecipients($check_id));
            }
        }

        $should_slack = !!getenv('SLACK_NOTIFICATIONS') AND getenv('SLACK_NOTIFICATIONS') != 'false';
        if ($should_slack) {
            if ($status == 'up') {
                $this->slack($status, "$name", "Service $name is now UP.");
            }
            if ($status == 'down') {
                $this->slack($status, "$name", "Service $name is now DOWN.".($note ? "\n".$note : ''));
            }
        }

    }


    protected function __construct() {
        $this->store = Store::instance();
    }

    protected function buildEmailRecipients($check_id) {
        $recipients = [
            [
                'email' => getenv('EMAIL_RECIPIENT_EMAIL'),
                'name'  => getenv('EMAIL_RECIPIENT_NAME'),
                'type'  => 'to',
            ],
        ];

        if (getenv('EMAIL_CC_EMAILS')) {
            $emails = explode('|', getenv('EMAIL_CC_EMAILS'));
            $names = explode('|', getenv('EMAIL_CC_NAMES'));

            foreach($emails as $offset => $email) {
                $recipients[] = [
                    'email' => $email,
                    'name'  => isset($names[$offset]) ? $names[$offset] : '',
                    'type'  => 'cc',
                ];
            }
        }

        return $recipients;
    }

    protected function email($subject, $text, $recipients) {
        try {
                
            // [
            //     'email' => 'recipient.email@example.com',
            //     'name'  => 'Recipient Name',
            //     'type'  => 'to',
            // ]
            $mandrill = new Mandrill(getenv('MANDRILL_API_KEY'));
            $message = array(
                'text'       => $text,
                'subject'    => $subject,
                'from_email' => getenv('EMAIL_FROM_EMAIL'),
                'from_name'  => getenv('EMAIL_FROM_NAME'),
                'to'         => $recipients,
                'headers'    => array('Reply-To' => 'no-reply@tokenly.co'),
            );
            $results = $mandrill->messages->send($message, true);
            $result = (($results and is_array($results)) ? $results[0] : $results);
            if (!$result OR $result['status'] != 'sent') {
                throw new Exception("Failed to send email: ".json_encode($result, 192), 1);
            }

        } catch (Exception $e) {
            Log::logError($e);
        }

    }


    protected function slack($status, $subject, $text) {
        $client = $this->getSlackClient();
        $client
            ->from($status == 'up' ? getenv('SLACK_USERNAME_UP') : getenv('SLACK_USERNAME_DOWN'))
            ->withIcon($status == 'up' ? ':white_check_mark:' : ':exclamation:')
            ->send('*'.$subject.'*'."\n".$text);

    }

    protected function getSlackClient() {
        if (!isset($this->slack_client)) {
            $settings = [
                'username'   => getenv('SLACK_USERNAME_UP'),
                'channel'    => getenv('SLACK_CHANNEL'),
                'link_names' => true,
            ];
            echo "endpoint: ".getenv('SLACK_ENDPOINT')."\n";
            $this->slack_client = new Client(getenv('SLACK_ENDPOINT'), $settings);
        }
        return $this->slack_client;
    }

}
