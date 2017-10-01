<?php
/**
 * Created by PhpStorm.
 * User: marius
 * Date: 10.09.17
 * Time: 00:29
 */

namespace OCA\Passwords\Helper\Http;

/**
 * Class RequestHelper
 *
 * @package OCA\Passwords\Helper
 */
class RequestHelper {

    const REQUEST_MAX_RETRIES = 5;
    const REQUEST_TIMEOUT     = 15;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var array
     */
    protected $post;

    /**
     * @var array
     */
    protected $header;

    /**
     * @var array
     */
    protected $json;

    /**
     * @var string
     */
    protected $userAgent = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:55.0) Gecko/20100101 Firefox/55.0';

    /**
     * @var int[]
     */
    protected $acceptResponseCodes = [200, 201, 202];

    /**
     * @var int
     */
    protected $retryTimeout = 0;

    /**
     * @var array
     */
    protected $info;

    /**
     * @var string
     */
    protected $response;

    /**
     * RequestHelper constructor.
     *
     * @param string|null $url
     */
    public function __construct(string $url = null) {
        if($url !== null) {
            $this->setUrl($url);
        }
    }

    /**
     * @param string $url
     *
     * @return RequestHelper
     */
    public function setUrl(string $url): RequestHelper {
        $this->url = $url;

        return $this;
    }

    /**
     * @param array $post
     *
     * @return RequestHelper
     */
    public function setPost(array $post): RequestHelper {
        $this->post = $post;

        return $this;
    }

    /**
     * @param array $header
     *
     * @return RequestHelper
     */
    public function setHeader(array $header): RequestHelper {
        $this->header = $header;

        return $this;
    }

    /**
     * @param array $json
     *
     * @return RequestHelper
     */
    public function setJson(array $json): RequestHelper {
        $this->json = $json;

        return $this;
    }

    /**
     * @param string $userAgent
     *
     * @return RequestHelper
     */
    public function setUserAgent(string $userAgent): RequestHelper {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * @param int[] $acceptResponseCodes
     *
     * @return RequestHelper
     */
    public function setAcceptResponseCodes(array $acceptResponseCodes): RequestHelper {
        $this->acceptResponseCodes = $acceptResponseCodes;

        return $this;
    }

    /**
     * @param int $retryTimeout
     *
     * @return RequestHelper
     */
    public function setRetryTimeout(int $retryTimeout): RequestHelper {
        $this->retryTimeout = $retryTimeout;

        return $this;
    }

    /**
     * @param string|null $url
     *
     * @return bool|mixed
     */
    public function send(string $url = null) {
        $ch = $this->prepareCurlRequest($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $this->response = curl_exec($ch);
        $this->info     = curl_getinfo($ch);
        curl_close($ch);

        if(!empty($this->acceptResponseCodes)) {
            if(!in_array($this->info['http_code'], $this->acceptResponseCodes)) return false;
        }

        return $this->response;
    }

    /**
     * @param int $maxRetries
     *
     * @return mixed
     */
    public function sendWithRetry($maxRetries = self::REQUEST_MAX_RETRIES) {
        $retries = 0;
        while ($retries < $maxRetries) {
            $result = $this->send();

            if($result !== false) return $result;
            if($this->retryTimeout) usleep($this->retryTimeout * 1000);
            $retries++;
        }

        return null;
    }

    /**
     * @param string|null $key
     *
     * @return array
     */
    public function getInfo(string $key = null) {
        return $key === null ? $this->info:$this->info[ $key ];
    }

    /**
     * @return mixed
     */
    public function getResponse() {
        return $this->response;
    }

    /**
     * @param string $url
     *
     * @return resource
     */
    protected function prepareCurlRequest(string $url = null) {
        $ch = curl_init($url == null ? $this->url:$url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, static::REQUEST_TIMEOUT);

        if(!empty($this->post)) {
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->post));
        }

        if(!empty($this->json)) {
            $json = json_encode($this->json);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $this->header['Content-Type']   = 'application/json';
            $this->header['Content-Length'] = strlen($json);
        }

        if(!empty($this->header)) {
            $header = [];

            foreach ($this->header as $key => $value) {
                $header[] = "{$key}: {$value}";
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }

        return $ch;
    }
}