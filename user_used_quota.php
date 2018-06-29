<?php

$serverName = $db_host;
$userName = $db_user;
$password = $db_pass;
$dbName = "root_cwp";

// Create connection
$conn = new mysqli($serverName, $userName, $password, $dbName);

class CwpUserUsedQuota
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function calculate()
    {
        $quota['home'] = $this->getUsedHomeQuota();
        $quota['mysql'] = $this->getUsedMysqlQuota();

        $emailQuota = $this->getUsedEmailQuota();
        $usersEmailDomains = $this->getUserEmailDomains();

        $quota['email'] = $this->joinEmailDomainsWithQuota($usersEmailDomains, $emailQuota);

        return $quota;
    }

    public function getUsedHomeQuota()
    {
        $homeQuota = shell_exec("du -bs /home/*");
        $homeQuotaAll = explode("\n", $homeQuota);

        $quota = [];
        foreach ($homeQuotaAll as $homeQuotaInfo) {
            if (!empty($homeQuotaInfo)) {
                $userQuotaInfo = explode('/', trim($homeQuotaInfo), 2);
                $userName = trim(str_replace('home/', '', $userQuotaInfo[1]));
                $userQuota = trim($userQuotaInfo[0]);
                $quota[$userName] = (int) $userQuota;
            }
        }
        return $quota;
    }

    public function getUsedMysqlQuota()
    {
        $allQuota = shell_exec("du -bs /var/lib/mysql/*");
        $allQuotaRows = explode("\n", $allQuota);

        $quota = [];
        foreach ($allQuotaRows as $rowQuotaInfo) {
            if (!empty($rowQuotaInfo)) {
                $userQuotaInfo = explode('/', trim($rowQuotaInfo), 2);
                $fileQuota = trim($userQuotaInfo[0]);
                $userNameDb = trim(str_replace('var/lib/mysql/', '', $userQuotaInfo[1]));
                $userNameDbExploded = explode('_', trim($userNameDb), 2);
                $userName = $userNameDbExploded[0];
                if (isset($userNameDbExploded[1])) {
                    $dbName = $userNameDbExploded[1];
                };
                if (isset($quota[$userName])) {
                    if (isset($dbName)) {
                        $quota[$userName]['db'][$dbName] = (int) $fileQuota;
                    }
                    $quota[$userName]['db_quota'] += (int) $fileQuota;
                    $quota[$userName]['db_count']++;
                } else {
                    if (isset($dbName)) {
                        $quota[$userName]['db'][$dbName] = (int) $fileQuota;
                    }
                    $quota[$userName]['db_quota'] = (int) $fileQuota;
                    $quota[$userName]['db_count'] = 1;
                }
                unset($dbName);
            }
        }

        return $quota;
    }

    public function getUsedEmailQuota()
    {
        $emailQuota = shell_exec("du -bs /var/vmail/*");
        $emailQuotaAll = explode("\n", $emailQuota);

        $quota = [];
        foreach ($emailQuotaAll as $emailQuotaInfo) {
            if (!empty($emailQuotaInfo)) {
                $domainQuotaInfo = explode('/', trim($emailQuotaInfo), 2);
                $domainName = trim(str_replace('var/vmail/', '', $domainQuotaInfo[1]));
                $emailQuota = trim($domainQuotaInfo[0]);
                $quota[$domainName] = (int) $emailQuota;
            }
        }

        return $quota;
    }

    public function getUserEmailDomains()
    {

        $sql = "
		SELECT 
			cu.username,
			cu.domain
		FROM 
			root_cwp.user cu
		UNION ALL
		SELECT 
			cd.user,
			cd.domain
		FROM 
			root_cwp.domains cd
		UNION ALL
		SELECT 
			cs.user,
			CONCAT(cs.subdomain, '.', cs.domain)
		FROM 
			root_cwp.subdomains cs
		";

        $result = $this->conn->query($sql);
        $usersDomains = [];
        while ($row = $result->fetch_assoc()) {
            $usersDomains['user'][$row['username']][$row['domain']] = [];
            $usersDomains['domain'][$row['domain']] = $row['username'];
        }

        return $usersDomains;
    }

    public function joinEmailDomainsWithQuota(array $usersDomains, array $emailQuota)
    {
        $data = [];
        foreach($emailQuota as $domain => $quota) {
            $user = $usersDomains['domain'][$domain];
            if (isset($data[$user])) {
                $data[$user] += (int) $quota;
            } else {
                $data[$user] = (int) $quota;
            }
        }

        return $data;
    }

}

$cwpUsersQuota = new CwpUsersUsedQuota($conn);
$quota = $cwpUsersQuota->calculate();

?>

<div id="tablecontainer">
    <?php
    $sql = "SELECT u.*, p.package_name, p.disk_quota FROM user u, packages p WHERE p.id = u.package ORDER by u.id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $meta = $stmt->result_metadata();
    $result = null;
    $params = null;
    while ($field = $meta->fetch_field()) {
        $params[] = &$row[$field->name];
    }
    call_user_func_array(array($stmt, 'bind_result'), $params);
    while ($stmt->fetch()) {
        foreach ($row as $key => $val) {
            $c[$key] = $val;
        }
        $result[] = $c;
    }
    $stmt->close();
    ?>
	<table border=1 id="dbtable">
		<tr>
			<th class="center">LP.</th>
			<th class="center">User Name</th>
			<th class="center">Package name</th>
			<th class="center">Package quota</th>
			<th class="center">Home dir quota</th>
			<th class="center">MySql quota</th>
			<th class="center">Mail quota</th>
			<th class="center">Used quota</th>
			<th class="center">Free quota</th>
			<th class="center">Percent</th>
		</tr>
        <?php
        $allAccounts = count($result);
        $sum['home'] = 0;
        $sum['email'] = 0;
        $sum['mysql']['count'] = 0;
        $sum['mysql']['quota'] = 0;
        $sum['all'] = 0;
        $sum['free'] = 0;
        for ($i = 0; $i <= $allAccounts - 1; $i++) :
            $userName = $result[$i]['username'];
            ?>
			<tr>
				<td class="center"><?php echo $i+1 ?>.</td>
				<td class="center"><?php echo $userName ?></td>
				<td class="center"><?php echo($result[$i]['package_name']) ?></td>
				<td class="right"><?php echo round($result[$i]['disk_quota'] / 1024, 2) ?> GB</td>
				<td class="right">
                    <?php
                    $sum['home'] += $quota['home'][$userName];
                    echo round($quota['home'][$userName] / 1024 / 1024 / 1024, 2);
                    ?> GB
				</td>
				<td class="right">
                    <?php
                    if (isset($quota['mysql'][$userName])) {
                        $sum['mysql']['count'] += $quota['mysql'][$userName]['db_count'];
                        $sum['mysql']['quota'] += $quota['mysql'][$userName]['db_quota'];
                        echo round($quota['mysql'][$userName]['db_quota'] / 1024 / 1024 / 1024, 2);
                        echo ' GB ';
                        echo "[{$quota['mysql'][$userName]['db_count']}]";
                    } else {
                        echo 0 . ' GB';
                    }
                    ?>
				</td>
				<td class="right">
                    <?php
                    if (isset($quota['email'][$userName])) {
                        $sum['email'] += $quota['email'][$userName];
                        echo round($quota['email'][$userName] / 1024 / 1024 / 1024, 2);
                        echo ' GB ';
                    } else {
                        echo 0 . ' GB';
                    }
                    ?>
				</td>
				<td class="right">
                    <?php
                    $homeQuota = 0;
                    if (isset($quota['home'][$userName])) {
                        $homeQuota = $quota['home'][$userName];
                    }

                    $mysqlQuota = 0;
                    if (isset($quota['mysql'][$userName])) {
                        $mysqlQuota = $quota['mysql'][$userName]['db_quota'];
                    }

                    $emailQuota = 0;
                    if (isset($quota['email'][$userName])) {
                        $emailQuota = $quota['email'][$userName];
                    }

                    $allQuota = $homeQuota + $mysqlQuota + $emailQuota;
                    $sum['all'] += $allQuota;

                    echo round($allQuota / 1024 / 1024 / 1024, 2);
                    echo ' GB ';

                    ?>
				</td>
				<td class="right">
                    <?php

                    $packageMaxQuotaBytes = $result[$i]['disk_quota'] * 1024 * 1024;
                    $freeQuota = $packageMaxQuotaBytes - $allQuota;
                    $sum['free'] += $freeQuota;

                    echo round($freeQuota / 1024 / 1024 / 1024, 2);
                    echo ' GB ';

                    ?>
				</td>
				<td>
                    <?php

                    $packageMaxQuotaBytes = $result[$i]['disk_quota'] * 1024 * 1024;
                    if ($packageMaxQuotaBytes == "0") {
                        $usedQuotaProgress = 0;
                        echo "[Unlimited]";
                    } else {
                        $usedQuotaPercent = round($allQuota * 100 / $packageMaxQuotaBytes, 2);
                        $usedQuotaProgress = round($allQuota * 100 / $packageMaxQuotaBytes, 0);
                        echo "[$usedQuotaPercent %]";
                    }

                    $progressBarClass = 'progressBarGreen';
                    if ($usedQuotaProgress > 50) {
                        $progressBarClass = 'progressBarOrange';
                    }
                    if ($usedQuotaProgress > 90) {
                        $progressBarClass = 'progressBarRed';
                    }

                    ?>
					<div class="progressBox">
						<div class='<?=$progressBarClass;?>' style="width:<?=$usedQuotaProgress;?>px;"></div>
					</div>
				</td>
			</tr>
        <?php endfor; ?>
		<thead>
		<tr>
			<td class="center"><?=$allAccounts;?></td>
			<td colspan="3" class="right">Sum:</td>
			<td class="right">
                <?php echo round($sum['home'] / 1024 / 1024 / 1024, 2); ?> GB
			</td>
			<td class="right">
                <?php echo round($sum['mysql']['quota'] / 1024 / 1024 / 1024, 2); ?> GB
				[<?php echo $sum['mysql']['count']; ?>]
			</td>
			<td class="right">
                <?php echo round($sum['email'] / 1024 / 1024 / 1024, 2); ?> GB
			</td>
			<td class="right">
                <?php echo round($sum['all'] / 1024 / 1024 / 1024, 2); ?> GB
			</td>
			<td class="right">
                <?php echo round($sum['free'] / 1024 / 1024 / 1024, 2); ?> GB
			</td>
			<td></td>
		</tr>
		</thead>
	</table>
</div>

<style type="text/css">
	#dbtable {
		width: 100%;
		margin-bottom: 20px;
	}

	#dbtable td, #dbtable th {
		padding: 6px;
	}
	.center {
		text-align: center;
	}
	.right {
		text-align: right;
	}
	.progressBox {
		width: 100px;
		height: 10px;
		border: 1px solid;
	}
	.progressBarGreen {
		height: 8px;
		background-color: green;
	}
	.progressBarOrange {
		height: 8px;
		background-color: orange;
	}
	.progressBarRed {
		height: 8px;
		background-color: red;
	}
</style>
