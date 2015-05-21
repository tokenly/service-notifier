<?php

namespace App;

use App\Cmd;
use App\Log;
use App\Store;
use Exception;
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
        $this->email("Service UP: $name", "Service $name is now UP.\n\n".date("Y-m-d H:i:s"), $this->buildRecipients($check_id));

    }
    protected function handleGoDown($check_id, $event) {
        print "Now DOWN: $check_id\n";
        $name = $event['Name'];
        $this->email("Service DOWN: $name", "Service $name is now DOWN.\n\n".date("Y-m-d H:i:s"), $this->buildRecipients($check_id));

    }

    protected function changeStatus($status, $check_id, $event) {
        $state = $this->store->findOrCreateState($check_id);
        $state->status    = $status;
        $state->timestamp = time();
        $this->store->storeState($state);
    }



    protected function __construct() {
        $this->store = Store::instance();
    }

    protected function buildRecipients($check_id) {
        return [
            [
                'email' => 'dweller@devonweller.com',
                'name'  => 'Devon Weller',
                'type'  => 'to',
            ],
        ];
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

}

