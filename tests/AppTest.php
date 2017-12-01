<?php

use Guc\App;

class AppTest extends PHPUnit_Framework_TestCase {

    public function testGetDB() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with('USE `testwiki_p`;')
            ->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->once())->method('openDB')
            ->with('testwiki_p', 'eg1.web.db.svc.eqiad.wmflabs')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('testwiki', 'eg1')
        );
    }

    public function testGetDBCached() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['testwiki_p', 'eg1.web.db.svc.eqiad.wmflabs'],
                ['otherwiki_p', 'eg2.web.db.svc.eqiad.wmflabs']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('testwiki', 'eg1'),
            'First on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('otherwiki', 'eg1'),
            'Second on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('otherwiki', 'eg2'),
            'First on eg2'
        );
    }
}
