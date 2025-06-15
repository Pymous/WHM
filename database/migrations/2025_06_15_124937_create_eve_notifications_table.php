<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    Here is a few example notifications : 
          6 => array:6 [▼
    "notification_id" => 2186238278
    "sender_id" => 1000125
    "sender_type" => "corporation"
    "text" => """
      killMailHash: aec18a4a960509d48aa90051422c1d9a32777b55
      killMailID: 127881914
      victimID: 96591856
      victimShipTypeID: 33475
      """
    "timestamp" => "2025-06-14T19:42:00Z"
    "type" => "KillReportFinalBlow"
  ]

  
    "is_read" => true
    "notification_id" => 2185781610
    "sender_id" => 1000137
    "sender_type" => "corporation"
    "text" => """
      charID: 2122395483
      listOfTypesAndQty:
      - - 1
        - 47270
      solarsystemID: 31001703
      structureID: &id001 1047256494702
      structureShowInfoData:
      - showinfo
      - 35833
      - *id001
      structureTypeID: 35833
      """
    "timestamp" => "2025-06-13T23:41:00Z"
    "type" => "StructureItemsDelivered"

    
  114 => array:7 [▼
    "is_read" => true
    "notification_id" => 2177379015
    "sender_id" => 92221115
    "sender_type" => "character"
    "text" => """
      applicationText: talked on discord already :)
      charID: 92221115
      corpID: 98748326
      """
    "timestamp" => "2025-05-30T16:49:00Z"
    "type" => "CorpAppNewMsg"
  ]
    */
    public function up(): void
    {
        Schema::create('eve_notifications', function (Blueprint $table) {
            // Use the notification_id as the primary key
            $table->bigInteger('notification_id')->primary();
            $table->unsignedBigInteger('character_id')->index();
            $table->string('type'); // Type of notification
            $table->unsignedBigInteger('sender_id'); // ID of the sender (character or corporation)
            $table->string('sender_type'); // Type of the sender
            $table->timestamp('timestamp'); // When the notification was sent
            $table->text('text'); // The content of the notification
            $table->boolean('is_read')->nullable(); // Whether the notification has been read
            $table->boolean('is_broadcasted')->default(false); // Whether the notification has been broadcasted to Discord
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eve_notifications');
    }
};
