<?php

$serverName = $db_host;
$userName = $db_user;
$password = $db_pass;
$dbName = "root_cwp";

// Create connection
$conn = new mysqli($serverName, $userName, $password, $dbName);

class CwpUsersUsedQuota
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
                $quota[$userName] = $userQuota;
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
                        $quota[$userName]['db'][$dbName] = $fileQuota;
                    }
                    $quota[$userName]['db_quota'] =+ $fileQuota;
                    $quota[$userName]['db_count']++;
                } else {
                    if (isset($dbName)) {
                        $quota[$userName]['db'][$dbName] = $fileQuota;
                    }
                    $quota[$userName]['db_quota'] = $fileQuota;
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
                $quota[$domainName] = $emailQuota;
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
                $data[$user] =+ $quota;
            } else {
                $data[$user] = $quota;
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
    $sql = "SELECT u.*, p.package_name, p.disk_quota FROM user u, packages p WHERE p.id = u.package";
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
            <th>ID</th>
            <th>User Name</th>
            <th>Package name</th>
            <th>Package quota</th>
            <th>Home dir quota</th>
            <th>MySql quota</th>
            <th>Mail quota</th>
            <th>Used quota</th>
            <th>Free quota</th>
            <th>Percent</th>
        </tr>
        <?php
        for ($i = 0; $i <= count($result) - 1; $i++) :
            $userName = $result[$i]['username'];
            ?>

            <tr>
                <td><?php echo($result[$i]['id']) ?></td>
                <td><?php echo $userName ?></td>
                <td><?php echo($result[$i]['package_name']) ?></td>
                <td><?php echo($result[$i]['disk_quota'] / 1024) ?> GB</td>
                <td>
                    <?php
                    echo round($quota['home'][$userName] / 1024 / 1024 / 1024, 2);
                    ?> GB
                </td>
                <td>
                    <?php
                    if (isset($quota['mysql'][$userName])) {
                        echo round($quota['mysql'][$userName]['db_quota'] / 1024 / 1024 / 1024, 2);
                        echo ' GB ';
                        echo "[{$quota['mysql'][$userName]['db_count']}]";
                    } else {
                        echo 0 . ' GB';
                    }
                    ?>
                </td>
                <td>
                    <?php

                    if (isset($quota['email'][$userName])) {
                        echo round($quota['email'][$userName] / 1024 / 1024 / 1024, 2);
                        echo ' GB ';
                    } else {
                        echo 0 . ' GB';
                    }

                    ?>
                </td>
                <td>
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

                    echo round($allQuota / 1024 / 1024 / 1024, 2);
                    echo ' GB ';

                    ?>
                </td>
                <td>
                    <?php

                    $packageMaxQuotaBytes = $result[$i]['disk_quota'] * 1024 * 1024;
                    $freeQuota = $packageMaxQuotaBytes - $allQuota;

                    echo round($freeQuota / 1024 / 1024 / 1024, 2);
                    echo ' GB ';

                    ?>
                </td>
                <td>
                    <?php

                    $packageMaxQuotaBytes = $result[$i]['disk_quota'] * 1024 * 1024;
                    $usedQuotaPercent = round($allQuota * 100 / $packageMaxQuotaBytes, 2);
                    $usedQuotaProgress = round($allQuota * 100 / $packageMaxQuotaBytes, 0);

                    echo "[$usedQuotaPercent %]";

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
    </table>
</div>

<style type="text/css">
    #dbtable {
        width: 100%;
        margin-bottom: 20px;
    }

    #dbtable td, #dbtable th {
        padding: 8px;
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