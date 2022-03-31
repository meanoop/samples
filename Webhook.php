<?php
defined('BASEPATH') or exit('No direct script access allowed');

// require APPPATH . 'third_party/vendor/autoload.php';
require APPPATH . 'third_party/vendor/twilio/sdk/src/Twilio/autoload.php';

require APPPATH.'third_party/vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

class Webhook extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('stripe');
    }

    public function stripe()
    {
        $response_code = 200;
        try {
            if ($event = $this->stripe->verify_event()) {
                $object = $event->data->object;
                if ($object->status == 'succeeded' && $object->paid) {
                    $customer_id = $this->db->get_where('users', ['stripe_id' => $object->customer])->row('id');
                    $data['payment_gateway'] = 'stripe';
                    $data['gateway_reference'] = $event->id;
                    $data['amount'] = $object->amount / 100;
                    $data['currency'] = $object->currency;
                    $data['paid_at'] = $object->created;
                    $data['receipt'] = $object->receipt_url;
                    $data['description'] = $object->description;
                    $data['user_id'] = $customer_id;
                    $data['json'] = json_encode($event);
                    //$this->db->insert('payment_history', $data);
                    //file_put_contents('stripe_event.log', $this->db->last_query());
                }
            } else {
                $response_code = 400;
            }
        } catch (Exception $e) {
            file_put_contents('stripe_error.log', $e->getMessage());
            $response_code = 400;
        }
        http_response_code($response_code);
        exit;
    }

    public function refresh_stripe()
    {
        $this->load->library('stripe');
        $id = false;
        $userId = $this->ion_auth->get_user_id();
        $user = $this->db->get_where('displayer', ['user_id' => $userId])->row();
        try {
            $oneTimeLogin = $this->stripe->create_account_link($user->stripe_connect_id);
            redirect($oneTimeLogin->url);
        } catch (Exception $e) {
            addPageMessage($e->getMessage(), 'error');
        }
        redirect('auth/edit_profile#payment');
    }

    public function stripe_login()
    {
        $this->load->library('stripe');
        $userId = $this->ion_auth->get_user_id();
        $user = $this->db->get_where('displayer', ['user_id' => $userId])->row();
        try {
            $oneTimeLogin = $this->stripe->create_login_link($user->stripe_connect_id);
            redirect($oneTimeLogin->url);
        } catch (Exception $e) {
            addPageMessage($e->getMessage(), 'error');
            $this->refresh_stripe();
        }
    }

    public function TwilioInbox()
    {
        ignore_user_abort();
        set_time_limit(0);
        http_response_code(200);
        echo "Received Request\n";
        try {
            $this->load->library('gd');

            
            $msgArray = $_POST; //uncomment this line

            $msg   = $msgArray["Body"];

            $from  = $msgArray["From"];

            $smsID = $msgArray['SmsSid'];

            $from_remove_country_code =  substr($from, -10);



           

            $this->db->where("(phone IN ('{$from}','{$from_remove_country_code}') OR business_phone IN ('{$from}','{$from_remove_country_code}') ) OR (RIGHT(phone, 10) IN ('{$from}','{$from_remove_country_code}') OR RIGHT(business_phone, 10) IN ('{$from}','{$from_remove_country_code}') )");

            $user       = $this->db->get('users')->row();
            $interval   = siteConfig('user_sms_interval');
            $limit      = siteConfig('sms_per_day');

            $todaysSms  = $this->db->order_by('created_date', 'desc')->get_where('videos', ['video_type'    => 'displayer_sms', 'date(created_date)' => date('Y-m-d')])->result();

            if (count($todaysSms) >= $limit) {
                echo "Todays SMS Limit exceeded (limit : {$limit})\n";
                return;
            }

            if (count($todaysSms)) {
                $lastSmsTime  = strtotime($todaysSms[0]->created_date);
                $intervalTime = strtotime("-{$interval}mins");

                // if ($lastSmsTime < $intervalTime) {
                //     echo "Wait some time before sending another sms\n";
                //     return;
                // }
            }

            if (!empty($user) && $this->ion_auth->in_group('displayer', $user->id)) {
                if ($user->s3_directory =="") {
                    $this->db->update('users', ['s3_directory' => $user->id], ['id' => $id]);
                }

                $s3Directory = $user->s3_directory;

                $remove_character = array("\n", "\r\n", "\r");

                $msg = str_replace($remove_character, ' ', $msg);


                $this->gd->createImage($msg, $user->business_name);


                $imageName =  "{$smsID}_".time();
                $imageFile =  'uploads/displayer_sms/'.$imageName.'.jpg';

                $this->gd->saveAsJpg($imageName, getcwd().'/uploads/displayer_sms/');

                $videoFile = imageToVideo($imageFile);

                if (!is_readable($videoFile)) {
                    throw new Exception("Error converting Image to Video\n", 144);
                }

                $fileKey  = $s3Directory.'/text_original/'.$imageName.'.mp4';

                $s3Client = new S3Client([
                    'version'     => 'latest',
                    'region'      => 'us-east-2',
                    'credentials' => [
                        'key'    => siteConfig('s3_access_key'),
                        'secret' => siteConfig('s3_secret_key'),
                    ],
                ]);

                $uploader = new MultipartUploader($s3Client, $videoFile, [
                   'Bucket' =>  siteConfig('s3_bucket_videos'),
                   'Key'    =>  $fileKey,
                ]);
                $result  = $uploader->upload();

                $end_date   = date('Y-m-d H:i:s', strtotime('+30days'));
                $saved = $this->db->insert('videos', [
                    'title' 		=> strlen($msg) > 200 ? substr($msg, 0, 198).'..' : $msg,
                    'video_uid'  	=> $imageName,
                    'object_url'	=> $result['ObjectURL'],
                    'aws_file_key'	=> $fileKey,
                    'duration'		=> 30 ,
                    'created_date' 	=> date('Y-m-d H:i:s'),
                    'user_id'		=> $user->id,
                    'place_data'    => '[]',
                    'converted'     => 2,
                    'video_type'    => 'displayer_sms',
                    'language'      => 1,
                    'campaign_date'    => date('Y-m-d H:i:s')
                ]);


                if (!$saved) {
                    throw new Exception($this->db->error(), 184);
                }

                $video_id = $this->db->insert_id();
                $views = ['video_id' => $video_id, 'start_time' => time()];
                $views['end_time'] = time() + (siteConfig('sms_video_display_time') * 60);   // minutes to seconds
                // set the others video-logs uploaded by same user to expired
                $this->db->query("update sms_video_views s set s.expired =1 where (select count(*) from videos v where s.video_id = v.video_id and v.user_id = {$user->id}) > 0");
                // save current log
                $this->db->insert('sms_video_views', $views);

                $tsFile   = convertToTsFormat($videoFile);
                $fileKey  = $s3Directory.'/text_ts/'.$imageName.'.ts';
                $uploader = new MultipartUploader($s3Client, $tsFile, [
                   'Bucket' => siteConfig('s3_bucket_videos'),
                   'Key'    => $fileKey,
                ]);
                $uploader->upload();

                //place playlist in all devices
                $devices = $this->db->get_where('user_devices', ['user_id' => $user->id, 'status' => 'enabled'])->result();
                foreach ($devices as $device) {
                    $playlist_data = [
                        'uniqueid'  => $device->device_id,
                        'timestamp' => time(),
                        'video1'    => ['name' => $imageName, 'length' => 30]
                    ];


                    $videoDir =  "server/{$device->device_id}/videos/";
                    $tempDir  =  "server/{$device->device_id}/temp_videos/";
                    $oldFiles = $s3Client->getIterator('ListObjects', ['Bucket' => siteConfig('s3_bucket_analytics'), 'Prefix' => $videoDir]);
                    foreach ($oldFiles as $file) {
                        $fKey = $file['Key'];
                        $s3Client->copyObject([
                           'Bucket'               =>  siteConfig('s3_bucket_analytics'),
                           'Key'                  =>  str_replace($videoDir, $tempDir, $fKey),
                           'CopySource'           =>  siteConfig('s3_bucket_analytics')."/{$fKey}",
                       ]);
                        echo $fKey.'<hr>';
                        $s3Client->deleteObject(['Bucket' => siteConfig('s3_bucket_analytics'), 'Key'    => $fKey]);
                    }

                    $playListKey = "{$videoDir}playlist.json";

                    $s3Client->putObject([
                       'Bucket' => siteConfig('s3_bucket_analytics'),
                       'Key'    => $playListKey,
                       'Body'   => json_encode($playlist_data)
                   ]);
                    if ($s3Client->doesObjectExist(siteConfig('s3_bucket_videos'), $fileKey)) {
                        $playListVideoFile = "server/{$device->device_id}/videos/{$imageName}.ts";
                        $s3Client->copyObject([
                           'Bucket'               =>  siteConfig('s3_bucket_analytics'),
                           'Key'                  =>  $playListVideoFile,
                           'CopySource'           =>  siteConfig('s3_bucket_videos')."/$fileKey",
                       ]);
                    } else {
                        throw new Exception("File : {$user->s3_directory}/ts/{$video->video_uid}.ts not found in bucket <br/>", 107);
                    }
                }
            } else {
                echo "User is not a displayer";
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }
}
