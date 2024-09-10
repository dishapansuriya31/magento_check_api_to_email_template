<?php

namespace Kitchen\CustomApi\Api;

interface CustomerInterface
{
    /**
     * @param string $email
     * @return array
     */
    public function checkCustomerByEmail($email);
}
