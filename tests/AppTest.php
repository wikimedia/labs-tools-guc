<?php

use Guc\App;

class AppTest extends PHPUnit_Framework_TestCase {

    public function testGetDB() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with('USE `testwiki_p`;')
            ->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->once())->method('getHostIp')
            ->with('eg1.web.db.svc.eqiad.wmflabs')
            ->willReturn('10.0.0.0');
        $app->expects($this->once())->method('openDB')
            // 'eg1' is expanded
            ->with('eg1.web.db.svc.eqiad.wmflabs', 'testwiki_p')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'testwiki')
        );
    }

    public function testGetDBWithFullHost() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with('USE `testwiki_p`;')
            ->willReturn($this->createMock(PDOStatement::class));

        $app->method('getHostIp')->willReturn(false);
        $app->expects($this->once())->method('openDB')
            // 'eg1.example' is not expanded
            ->with('eg1.example', 'testwiki_p')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1.example', 'testwiki')
        );
    }

    public static function provideGetDbInvalidHosts() {
        return [
            'array $host' => [
              [ 'eg1' ]
            ],
            'int $host' => [
              123
            ],
            'bool $host' => [
              false
            ],
            'null $host' => [
              null
            ]
        ];
    }

    /**
     * @dataProvider provideGetDbInvalidHosts
     */
    public function testGetDbInvalidHosts($host) {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();

        $app->expects($this->never())->method('getHostIp');
        $app->expects($this->never())->method('openDB');

        $this->expectException(Exception::class);
        $app->getDB($host, 'testwiki');
    }

    public function testGetDBCached() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->method('getHostIp')->willReturn(false);
        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['eg1.web.db.svc.eqiad.wmflabs', 'testwiki_p'],
                ['eg2.web.db.svc.eqiad.wmflabs', 'otherwiki_p']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'testwiki'),
            'First on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'otherwiki'),
            'Second on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg2', 'otherwiki'),
            'First on eg2'
        );
    }

    public function testGetDbReopenClosedDB() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->method('getHostIp')->willReturn(false);
        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['eg1.web.db.svc.eqiad.wmflabs', 'awiki_p'],
                ['eg1.web.db.svc.eqiad.wmflabs', 'cwiki_p']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'awiki'),
            'A on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'bwiki'),
            'B on eg1'
        );

        $app->closeDB('eg1');

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'cwiki'),
            'C on eg1'
        );
    }

    public function testCloseAllDBs() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->method('getHostIp')->willReturn(false);
        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['eg1.example', 'wikione_a_p'],
                // Re-open connection after close
                ['eg1.example', 'wikione_b_p']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1.example', 'wikione_a'),
            'A on eg1'
        );

        $app->closeAllDBs();

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1.example', 'wikione_b'),
            'C on eg1'
        );
    }

    public function testGetDbReuseSameIp() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB', 'getHostIp'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->method('getHostIp')->will($this->returnValueMap([
          ['eg1.web.db.svc.eqiad.wmflabs', '10.0.0.1'],
          // eg2 same as eg1
          ['eg2.web.db.svc.eqiad.wmflabs', '10.0.0.1'],
          // eg3 fails
          ['eg3.web.db.svc.eqiad.wmflabs', false],
          // eg4 separate
          ['eg4.web.db.svc.eqiad.wmflabs', '10.0.0.4'],
        ]));
        $app->expects($this->exactly(3))->method('openDB')
            ->withConsecutive(
                // eg1: connect
                ['eg1.web.db.svc.eqiad.wmflabs', null],
                // eg2: reuse eg1 (same ip)
                // eg3: connect (no ip)
                ['eg3.web.db.svc.eqiad.wmflabs', null],
                // eg4: connect (differnt ip)
                ['eg4.web.db.svc.eqiad.wmflabs', null]
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(PDO::class, $app->getDB('eg1'), 'eg1');
        $this->assertInstanceOf(PDO::class, $app->getDB('eg1'), 'eg1 (repeat)');
        $this->assertInstanceOf(PDO::class, $app->getDB('eg2'), 'eg2 reuses eg1');
        $this->assertInstanceOf(PDO::class, $app->getDB('eg2'), 'eg2 reuses eg1 (repeat)');
        $this->assertInstanceOf(PDO::class, $app->getDB('eg3'), 'eg3 fails, uses its own');
        $this->assertInstanceOf(PDO::class, $app->getDB('eg4'), 'eg4 uses its own');
        $this->assertInstanceOf(PDO::class, $app->getDB('eg3'), 'eg3 fails, reuses its own (repeat)');
    }
}
