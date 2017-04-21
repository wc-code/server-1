<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OC\Settings\BackgroundJobs;


use OC\Accounts\AccountManager;
use OC\BackgroundJob\Job;
use OC\BackgroundJob\JobList;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;

class VerifyUserData extends Job {

	/** @var  bool */
	private $retainJob = true;

	/** @var int max number of attempts to send the request */
	private $maxTry = 24;

	/** @var int how much time should be between two tries (1 hour) */
	private $interval = 3600;

	/** @var AccountManager */
	private $accountManager;

	/** @var IUserManager */
	private $userManager;

	/** @var IClientService */
	private $httpClientService;

	/** @var ILogger */
	private $logger;

	/** @var string */
	private $lookupServerUrl;

	/**
	 * VerifyUserData constructor.
	 *
	 * @param AccountManager|null $accountManager
	 * @param IUserManager|null $userManager
	 * @param IClientService|null $clientService
	 * @param IConfig|null $config
	 */
	public function __construct(AccountManager $accountManager = null,
								IUserManager $userManager = null,
								IClientService $clientService = null,
								ILogger $logger = null,
								IConfig $config = null
	) {
		$this->accountManager = $accountManager !== null ? $accountManager : \OC::$server->query(AccountManager::class);
		$this->userManager = $userManager !== null ? $userManager : \OC::$server->getUserManager();
		$this->httpClientService = $clientService !== null ? $clientService : \OC::$server->getHTTPClientService();
		$this->logger = $logger !== null ? $logger : \OC::$server->getLogger();

		if ($config !== null) {
			$this->lookupServerUrl = $config->getSystemValue('lookup_server', 'https://lookup.nextcloud.com');
		} else {
			$this->lookupServerUrl = \OC::$server->getConfig()->getSystemValue('lookup_server', 'https://lookup.nextcloud.com');
		}
	}

	/**
	 * run the job, then remove it from the jobList
	 *
	 * @param JobList $jobList
	 * @param ILogger $logger
	 */
	public function execute($jobList, ILogger $logger = null) {

		if ($this->shouldRun($this->argument)) {
			parent::execute($jobList, $logger);
			$jobList->remove($this, $this->argument);
			if ($this->retainJob) {
				$this->reAddJob($jobList, $this->argument);
			}
		}

	}

	protected function run($argument) {

		$try = (int)$argument['try'] + 1;

		switch($argument['type']) {
			case AccountManager::PROPERTY_WEBSITE:
				$result = $this->verifyWebsite($argument);
				break;
			case AccountManager::PROPERTY_TWITTER:
			case AccountManager::PROPERTY_EMAIL:
				$result = $this->verifyViaLookupServer($argument, $argument['type']);
				break;
			default:
				// no valid type given, no need to retry
				$this->logger->error($argument['type'] . ' is no valid type for user account data.');
				$result = true;
		}

		if ($result === true || $try > $this->maxTry) {
			$this->retainJob = false;
		}
	}

	/**
	 * verify web page
	 *
	 * @param array $argument
	 * @return bool true if we could check the verification code, otherwise false
	 */
	protected function verifyWebsite(array $argument) {

		$result = false;

		$url = rtrim($argument['data'], '/') . '/' . 'CloudIdVerificationCode.txt';

		$client = $this->httpClientService->newClient();
		try {
			$response = $client->get($url);
		} catch (\Exception $e) {
			return false;
		}

		if ($response->getStatusCode() === Http::STATUS_OK) {
			$result = true;
			$publishedCode = $response->getBody();
			$user = $this->userManager->get($argument['uid']);
			// we don't check a valid user -> give up
			if ($user === null) {
				$this->logger->error($argument['uid'] . ' doesn\'t exist, can\'t verify user data.');
				return $result;
			}
			$userData = $this->accountManager->getUser($user);

			if ($publishedCode === $argument['verificationCode']) {

				$userData[AccountManager::PROPERTY_WEBSITE]['verified'] === AccountManager::VERIFIED;
			} else {
				$userData[AccountManager::PROPERTY_WEBSITE]['verified'] === AccountManager::NOT_VERIFIED;
			}

			$this->accountManager->updateUser($user, $userData);
		}

		return $result;
	}

	/**
	 * verify email address
	 *
	 * @param array $argument
	 * @param string $dataType
	 * @return bool true if we could check the verification code, otherwise false
	 */
	protected function verifyViaLookupServer(array $argument, $dataType) {

		$user = $this->userManager->get($argument['uid']);

		// we don't check a valid user -> give up
		if ($user === null) {
			$this->logger->error($argument['uid'] . ' doesn\'t exist, can\'t verify user data.');
			return true;
		}

		$localUserData = $this->accountManager->getUser($user);
		$cloudId = $user->getCloudId();

		// ask lookup-server for user data
		$lookupServerData = $this->queryLookupServer($cloudId);

		// for some reasons we couldn't read any data from the lookup server, try again later
		if (empty($lookupServerData)) {
			return false;
		}

		// lookup server has verification data for wrong user data (e.g. email address), try again later
		if ($lookupServerData[$dataType]['value'] !== $argument['data']) {
			return false;
		}

		// lookup server hasn't verified the email address so far, try again later
		if ($lookupServerData[$dataType]['verified'] === AccountManager::VERIFICATION_IN_PROGRESS) {
			return false;
		}

		$localUserData[$dataType]['verified'] === $lookupServerData[$dataType]['verified'];
		$this->accountManager->updateUser($user, $localUserData);

		return true;
	}

	/**
	 * @param string $cloudId
	 * @return array
	 */
	protected function queryLookupServer($cloudId) {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$this->lookupServerUrl . '/users?search=' . urlencode($cloudId),
				[
					'timeout' => 10,
					'connect_timeout' => 3,
				]
			);

			$body = json_decode($response->getBody(), true);

			foreach ($body as $lookup) {
				if ($lookup['federationId'] === $cloudId) {
					return $lookup;
				}
			}

		} catch (\Exception $e) {
			// do nothing, we will just re-try later
		}

		return [];
	}

	/**
	 * re-add background job with new arguments
	 *
	 * @param IJobList $jobList
	 * @param array $argument
	 */
	protected function reAddJob(IJobList $jobList, array $argument) {
		$jobList->add('OC\Settings\BackgroundJobs\VerifyUserData',
			[
				'verificationCode' => $argument['verificationCode'],
				'data' => $argument['data'],
				'type' => $argument['type'],
				'uid' => $argument['uid'],
				'try' => (int)$argument['try'] + 1,
				'lastRun' => time()
			]
		);
	}

	/**
	 * test if it is time for the next run
	 *
	 * @param array $argument
	 * @return bool
	 */
	protected function shouldRun(array $argument) {
		$lastRun = (int)$argument['lastRun'];
		return ((time() - $lastRun) > $this->interval);
	}

}
