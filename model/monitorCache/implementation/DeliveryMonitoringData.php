<?php
/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2015 (original work) Open Assessment Technologies SA;
 *
 *
 */

namespace oat\taoProctoring\model\monitorCache\implementation;

use oat\oatbox\user\User;
use oat\tao\helpers\UserHelper;
use oat\taoProctoring\helpers\DeliveryHelper;
use oat\taoProctoring\model\deliveryLog\DeliveryLog;
use oat\taoProctoring\model\implementation\DeliveryExecutionStateService;
use oat\taoProctoring\model\implementation\TestSessionService;
use oat\taoProctoring\model\monitorCache\DeliveryMonitoringData as DeliveryMonitoringDataInterface;
use oat\oatbox\service\ServiceManager;
use oat\taoProctoring\model\execution\DeliveryExecution as ProctoredDeliveryExecution;
use oat\taoProctoring\model\TestSessionConnectivityStatusService;
use oat\taoQtiTest\models\runner\session\TestSession;
use oat\taoQtiTest\models\runner\time\QtiTimer;
use oat\taoQtiTest\models\runner\time\QtiTimeStorage;
use qtism\runtime\tests\AssessmentTestSession;
use oat\taoDelivery\model\execution\DeliveryExecution;
use oat\taoProctoring\model\monitorCache\DeliveryMonitoringService;
use Zend\ServiceManager\ServiceLocatorAwareTrait;
use Zend\ServiceManager\ServiceLocatorAwareInterface;

/**
 * class DeliveryMonitoringData
 *
 * Represents data model of delivery execution.
 *
 * @package oat\taoProctoring
 * @author Aleh Hutnikau <hutnikau@1pt.com>
 */
class DeliveryMonitoringData implements DeliveryMonitoringDataInterface, ServiceLocatorAwareInterface
{
    use ServiceLocatorAwareTrait;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var DeliveryExecution
     */
    private $deliveryExecution;

    /**
     * @var AssessmentTestSession
     */
    private $testSession;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * @var array
     */
    private $requiredFields = [
        DeliveryMonitoringService::DELIVERY_EXECUTION_ID,
        DeliveryMonitoringService::STATUS,
    ];

    /** @var User */
    private $user;

    /**
     * DeliveryMonitoringData constructor.
     * @param DeliveryExecution $deliveryExecution
     */
    public function __construct(DeliveryExecution $deliveryExecution, $data)
    {
        $this->deliveryExecution = $deliveryExecution;
        $this->data = $data;
    }

    /**
     * (non-PHPdoc)
     * @see \oat\taoProctoring\model\monitorCache\DeliveryMonitoringData::update()
     */
    public function update($key, $value)
    {
       $this->addValue($key, $value, true);
    }

    /**
     * Add data
     * @param string $key
     * @param string $value
     * @param boolean $overwrite
     */
    public function addValue($key, $value, $overwrite = false)
    {
        if (!isset($this->data[$key]) || $overwrite) {
            $this->data[$key] = $value;
        }
    }

    /**
     * Save delivery execution
     * @param DeliveryExecution $deliveryExecution
     */
    public function setDeliveryExecution(DeliveryExecution $deliveryExecution)
    {
        $this->deliveryExecution = $deliveryExecution;
    }

    /**
     * Validate data
     * @return bool whether data is valid and can be saved.
     */
    public function validate()
    {
        $result = true;
        $this->errors = [];
        $data = $this->get();

        foreach ($this->requiredFields as $requiredField) {
            if (!isset($data[$requiredField])) {
                $result = false;
                $this->errors[$requiredField] = 'cannot be empty';
            }
        }
        return $result;
    }

    /**
     * Get list of errors.
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Get delivery execution data
     * @param bool $refresh
     * @return array
     */
    public function get($refresh = false)
    {
        if (empty($this->data) || $refresh) {
            $this->updateData();
        }
        return $this->data;
    }

    /**
     * Set test session
     * @param AssessmentTestSession $testSession
     */
    public function setTestSession(AssessmentTestSession $testSession)
    {
        $this->testSession = $testSession;
    }

    /**
     * @param array $keys
     */
    public function updateData(array $keys = null)
    {
        if ($keys === null) {
            $keys = [
                DeliveryMonitoringService::STATUS,
                DeliveryMonitoringService::CURRENT_ASSESSMENT_ITEM,
                DeliveryMonitoringService::TEST_TAKER,
                DeliveryMonitoringService::TEST_TAKER_FIRST_NAME,
                DeliveryMonitoringService::TEST_TAKER_LAST_NAME,
                DeliveryMonitoringService::AUTHORIZED_BY,
                DeliveryMonitoringService::START_TIME,
                DeliveryMonitoringService::END_TIME,
                DeliveryMonitoringService::REMAINING_TIME,
                DeliveryMonitoringService::EXTRA_TIME,
                DeliveryMonitoringService::DELIVERY_ID,
                DeliveryMonitoringService::DELIVERY_NAME,
            ];
        }
        foreach ($keys as $key) {
            $methodName = 'update' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
            if (method_exists($this, $methodName)) {
                $this->{$methodName}();
            }
        }
    }

    /**
     * Update test session state
     */
    private function updateStatus()
    {
        $status = $this->deliveryExecution->getState()->getUri();
        $this->addValue(DeliveryMonitoringService::STATUS, $status, true);
    }

    /**
     * Update connectivity status (online|offline)
     */
    private function updateConnectivity()
    {
        $status = $this->deliveryExecution->getState()->getUri();
        $testSessionConnectivityStatusService = $this->getServiceLocator()->get(TestSessionConnectivityStatusService::SERVICE_ID);

        if (ProctoredDeliveryExecution::STATE_ACTIVE == $status) {
            $lastConnectivity = $testSessionConnectivityStatusService->getLastOnline($this->deliveryExecution->getIdentifier());
        }else{
            // to ensure that during sorting by connectivity all similar statuses grouped together
            $lastConnectivity = crc32($status);
        }

        $this->addValue(DeliveryMonitoringService::CONNECTIVITY, $lastConnectivity, true);
    }

    /**
     * Update test-taker uri
     */
    private function updateTestTaker()
    {
        $this->addValue(DeliveryMonitoringService::TEST_TAKER, $this->deliveryExecution->getUserIdentifier(), true);
        $this->addExtraFieldsValues(true);
    }

    /**
     * Update uri of proctor authorized the delivery
     */
    private function updateAuthorizedBy()
    {
        $authorizedBy = null;
        $deliveryLog = $this->getDeliveryLog('TEST_AUTHORISE');
        if (!empty($deliveryLog) && isset($deliveryLog[0]['data']['proctorUri'])) {
            $authorizedBy = $deliveryLog[0]['data']['proctorUri'];
        }
        $this->addValue(DeliveryMonitoringService::AUTHORIZED_BY, $authorizedBy, true);
    }

    /**
     * Update start time of delivery execution
     */
    private function updateStartTime()
    {
        list($usec, $sec) = explode(" ", $this->deliveryExecution->getStartTime());
        $startTime = ((float)$usec + (float)$sec);
        $this->addValue(DeliveryMonitoringService::START_TIME, $startTime, true);
    }

    /**
     * Update end time of delivery execution
     */
    private function updateEndTime()
    {
        $finishTime = $this->deliveryExecution->getFinishTime();
        if ($finishTime) {
            list($usec, $sec) = explode(" ", $this->deliveryExecution->getFinishTime());
            $finishTime = ((float)$usec + (float)$sec);
        } else {
            $finishTime = '';
        }
        $this->addValue(DeliveryMonitoringService::END_TIME, $finishTime, true);
    }

    /**
     * Update remaining time of delivery execution
     */
    private function updateRemainingTime()
    {
        $result = null;
        $remaining = 0;
        $hasTimer = false;

        $session = $this->getTestSession();

        if ($session !== null && $session->isRunning()) {
            $remaining = PHP_INT_MAX;
            if ($session instanceof TestSession) {
                $timeConstraints = $session->getRegularTimeConstraints();
            } else {
                $timeConstraints = $session->getTimeConstraints();
            }
            foreach ($timeConstraints as $tc) {
                // Only consider time constraints in force.
                if ($tc->getMaximumRemainingTime() !== false) {
                    $hasTimer = true;
                    $remaining = min($remaining, $tc->getMaximumRemainingTime()->getSeconds(true));
                }
            }
        }

        if ($hasTimer) {
            $result = $remaining . 's';
        }

        $this->addValue(DeliveryMonitoringService::REMAINING_TIME, $result, true);
    }
    
    /**
     * Update extra time allowed for the delivery execution
     */
    private function updateExtraTime()
    {
        $timer = DeliveryHelper::getDeliveryTimer($this->deliveryExecution);
        $this->addValue(DeliveryMonitoringService::EXTRA_TIME, $timer->getExtraTime(), true);
        $this->addValue(DeliveryMonitoringService::CONSUMED_EXTRA_TIME, $timer->getConsumedExtraTime(), true);
    }

    /**
     * Update delivery uri
     */
    private function updateDeliveryId()
    {
        $this->addValue(DeliveryMonitoringService::DELIVERY_ID, $this->deliveryExecution->getDelivery()->getUri(), true);
    }

    /**
     * Update test-taker's first name
     */
    private function updateTestTakerFirstName(){
        $result = UserHelper::getUserFirstName($this->getUser());
        $this->addValue(DeliveryMonitoringService::TEST_TAKER_FIRST_NAME, $result, true);
    }

    /**
     * Update test-taker's last name
     */
    private function updateTestTakerLastName(){
        $result = UserHelper::getUserLastName($this->getUser());
        $this->addValue(DeliveryMonitoringService::TEST_TAKER_LAST_NAME, $result, true);
    }

    /**
     * Update deliver label
     */
    private function updateDeliveryName()
    {
        $this->addValue(DeliveryMonitoringService::DELIVERY_NAME, $this->deliveryExecution->getDelivery()->getLabel(), true);
    }

    /**
     * @return User
     */
    private function getUser()
    {
        if (!$this->user){
             $this->user = UserHelper::getUser($this->deliveryExecution->getUserIdentifier());
        }
        return $this->user;
    }

    /**
     * @return string
     */
    private function getDeliveryLog($eventId = null)
    {
        $deliveryLogService = $this->getServiceLocator()->get(DeliveryLog::SERVICE_ID);
        return $deliveryLogService->get($this->deliveryExecution->getIdentifier(), $eventId);
    }

    /**
     * @param bool $overwrite
     */
    private function addExtraFieldsValues($overwrite = false)
    {
        $user = $this->getUser();
        if ($user) {
            $fields = DeliveryHelper::getExtraFieldsProperties();
            foreach ($fields as $field) {

                $values = $user->getPropertyValues($field['property']);
                if (!empty($values) && is_array($values)) {
                    $this->addValue($field['id'], (string)$values[0], $overwrite);
                }
            }
        }
    }

    /**
     * @return AssessmentTestSession
     */
    private function getTestSession()
    {
        if ($this->testSession === null) {
            $testSessionService = $this->getServiceLocator()->get(TestSessionService::SERVICE_ID);
            $this->testSession = $testSessionService->getTestSession($this->deliveryExecution);
        }
        return $this->testSession;
    }
}
