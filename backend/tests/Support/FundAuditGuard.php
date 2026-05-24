<?php

if (! function_exists('fundAuditGuardedTestPaths')) {
    /**
     * 需要在 afterEach 自动执行资金 invariant 的测试文件。
     */
    function fundAuditGuardedTestPaths(): array
    {
        return [
            'Feature/Database/FundTransactionUniqueIndexesTest.php',
            'Feature/FundAudit/FundInvariantsTest.php',
            'Feature/Http/Controllers/Admin/FundControllerTest.php',
            'Feature/Http/Controllers/User/FundControllerTest.php',
            'Feature/Http/Controllers/User/TopUpControllerTest.php',
            'Feature/Models/FundCasTest.php',
            'Feature/Models/FundTest.php',
            'Feature/Services/Order/SyncedCancelRefundTest.php',
            'Unit/Services/Acme/ActionTest.php',
            'Unit/Services/Order/ActionTest.php',
        ];
    }
}

if (! function_exists('fundAuditGuardExcludedTestPaths')) {
    /**
     * 名称像资金测试但不适合接入 afterEach invariant 的文件。
     */
    function fundAuditGuardExcludedTestPaths(): array
    {
        return [
            'Feature/Commands/FundAuditCommandTest.php',
            'Feature/Concurrent/AdminFundDestroyRaceTest.php',
            'Feature/Concurrent/FundCasTest.php',
            // 该文件直接 Transaction::create 绕开 Fund::createRecord，
            // 会制造合法 fixture 用的孤儿 transaction，不适合 afterEach 守门。
            'Feature/Models/TransactionTest.php',
            'Unit/FundAuditGuardCoverageTest.php',
            'Unit/Models/FundCasTransactionGuardTest.php',
            'Unit/Services/Notification/Builders/FinanceAuditMailNotificationBuilderTest.php',
        ];
    }
}

if (! function_exists('fundAuditGuardCandidatePattern')) {
    /**
     * 用于元测试扫描未来新增的疑似资金测试文件。
     */
    function fundAuditGuardCandidatePattern(): string
    {
        return '/(^|\/).*?(Fund|Transaction|TopUp|FinanceAudit).*?Test\.php$/i';
    }
}
