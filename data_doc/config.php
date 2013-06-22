<?php

$configArray = array(
	"DBServer" => "localhost",
	"DBPort" => "27017",
	"DBDatabase" => "userlib",
	"DBPrefix" => "", # useless so far

	"loginEnabled" => true,
	"registerEnabled" => true,
	"needApproval" => true,

	"passwordAlgorithm" => "bcrypt",
	"passwordSaltLength" => 20,
	"passwordCpuDifficulty" => 16384,
	"passwordMemDifficulty" => 8,
	"passwordParallelDifficulty" => 1,
	"passwordKeyLength" => 32,

	"passwordRounds" => 10,

	"activationCodeLength" => 20,
	"sendMailAddress" => "noreply@localhost",
	"autoLogoutTime" => 50000,
	"maxLoginAttempts" => 5,
	"loginBlockTime" => 3600,
	"secureSessions" => false
);


$config = new Config($configArray);
