<?php

namespace OCA\Passwords\Controller;

use OC\AppFramework\Http\Request;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\Passwords\AppInfo\Application;
use OCA\Passwords\Encryption\SimpleEncryption;
use OCA\Passwords\Services\ConfigurationService;
use OCA\Passwords\Services\LoggingService;
use OCA\Passwords\Services\NotificationService;
use OCA\Passwords\Services\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Util;

/**
 * Class PageController
 *
 * @package OCA\Passwords\Controller
 */
class PageController extends Controller {

    const WEBUI_TOKEN      = 'webui_token';
    const WEBUI_TOKEN_ID   = 'webui_token_id';
    const WEBUI_TOKEN_HELP = 'https://git.mdns.eu/nextcloud/passwords/wikis/Users/F.A.Q';

    /**
     * @var ISession
     */
    protected $session;

    /**
     * @var string
     */
    protected $userId;

    /**
     * @var ISecureRandom
     */
    protected $random;

    /**
     * @var SimpleEncryption
     */
    protected $encryption;

    /**
     * @var IUserManager
     */
    protected $userManager;

    /**
     * @var IL10N
     */
    protected $localisation;

    /**
     * @var IProvider
     */
    protected $tokenProvider;

    /**
     * @var ConfigurationService
     */
    protected $config;

    /**
     * @var SettingsService
     */
    protected $settingsService;
    /**
     * @var LoggingService
     */
    private $logger;
    /**
     * @var NotificationService
     */
    private $notificationService;

    /**
     * PageController constructor.
     *
     * @param null|string          $userId
     * @param IRequest             $request
     * @param ISession             $session
     * @param IL10N                $localisation
     * @param ISecureRandom        $random
     * @param LoggingService       $logger
     * @param IProvider            $tokenProvider
     * @param IUserManager         $userManager
     * @param SimpleEncryption     $encryption
     * @param ConfigurationService $config
     * @param SettingsService      $settingsService
     * @param NotificationService  $notificationService
     */
    public function __construct(
        ?string $userId,
        IRequest $request,
        ISession $session,
        IL10N $localisation,
        ISecureRandom $random,
        LoggingService $logger,
        IProvider $tokenProvider,
        IUserManager $userManager,
        SimpleEncryption $encryption,
        ConfigurationService $config,
        SettingsService $settingsService,
        NotificationService $notificationService
    ) {
        parent::__construct(Application::APP_NAME, $request);
        $this->random              = $random;
        $this->userId              = $userId;
        $this->config              = $config;
        $this->logger              = $logger;
        $this->session             = $session;
        $this->encryption          = $encryption;
        $this->userManager         = $userManager;
        $this->localisation        = $localisation;
        $this->tokenProvider       = $tokenProvider;
        $this->settingsService     = $settingsService;
        $this->notificationService = $notificationService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @throws \OCA\Passwords\Exception\ApiException
     */
    public function index(): TemplateResponse {

        $isSecure = $this->checkIfHttpsUsed();
        if($isSecure) {
            $this->getUserSettings();
            //$this->includeBrowserPolyfills();
            Util::addHeader('meta', ['name' => 'pwat', 'content' => $this->generateToken()]);
        } else {
            $this->destroyToken();
        }

        $response = new TemplateResponse(
            $this->appName,
            'index',
            ['https'   => $isSecure]
        );

        $csp = new ContentSecurityPolicy();
        $csp->addAllowedScriptDomain($this->request->getServerHost());
        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    /**
     * @return bool
     */
    protected function checkIfHttpsUsed(): bool {
        $httpsParam = $this->request->getParam('https', 'true') === 'true';

        return $this->request->getServerProtocol() === 'https' && $httpsParam;
    }

    /**
     * @return null|string
     */
    protected function generateToken(): string {
        try {
            $token   = $this->config->getUserValue(self::WEBUI_TOKEN, false);
            $tokenId = $this->config->getUserValue(self::WEBUI_TOKEN_ID, false);
            if($token !== false && $tokenId !== false) {
                try {
                    $iToken = $this->tokenProvider->getTokenById($tokenId);

                    if($iToken->getId() == $tokenId) return $this->encryption->decrypt($token);
                } catch(InvalidTokenException $e) {
                } catch(\Throwable $e) {
                    $this->notificationService->sendTokenErrorNotification($this->userId);
                    $this->logger
                        ->logException($e)
                        ->error('Failed to decrypt api token for '.$this->userId);
                }
            }

            $token       = $this->generateRandomDeviceToken();
            $name        = $this->localisation->t('Passwords WebUI Access (see %s)', [self::WEBUI_TOKEN_HELP]);
            $deviceToken = $this->tokenProvider->generateToken($token, $this->userId, $this->userId, null, $name, IToken::PERMANENT_TOKEN);
            $deviceToken->setScope(['filesystem' => false]);
            $this->tokenProvider->updateToken($deviceToken);
            $this->config->setUserValue(self::WEBUI_TOKEN, $this->encryption->encrypt($token));
            $this->config->setUserValue(self::WEBUI_TOKEN_ID, $deviceToken->getId());

            return $token;
        } catch(\Throwable $e) {
            $this->logger->logException($e);

            return '';
        }
    }

    /**
     * @return string
     */
    protected function generateRandomDeviceToken(): string {
        $groups = [];
        for($i = 0; $i < 5; $i++) {
            $groups[] = $this->random->generate(5, ISecureRandom::CHAR_HUMAN_READABLE);
        }

        return implode('-', $groups);
    }

    /**
     *
     */
    protected function destroyToken(): void {
        $tokenId = $this->config->getUserValue(self::WEBUI_TOKEN_ID, false);
        if($tokenId !== false) {
            $this->tokenProvider->invalidateTokenById(
                $this->userManager->get($this->userId),
                $tokenId
            );
            $this->config->deleteUserValue(self::WEBUI_TOKEN);
            $this->config->deleteUserValue(self::WEBUI_TOKEN_ID);
        }
    }

    /**
     * @throws \OCA\Passwords\Exception\ApiException
     */
    protected function getUserSettings() {
        Util::addHeader(
            'meta',
            [
                'name'    => 'settings',
                'content' => json_encode($this->settingsService->list())
            ]
        );
    }

    /**
     *
     */
    protected function includeBrowserPolyfills(): void {
        if($this->request->isUserAgent([Request::USER_AGENT_MS_EDGE])) {
            Util::addScript(Application::APP_NAME, 'Static/Polyfill/TextEncoder/encoding');
            Util::addScript(Application::APP_NAME, 'Static/Polyfill/TextEncoder/encoding-indexes');
        };
    }
}
