package com.arcadecloud.drive

import android.app.Application
import com.arcadecloud.drive.data.DriveRepository
import com.arcadecloud.drive.data.SessionVault
import com.arcadecloud.drive.transfer.TransferStore

class ArcadeApplication : Application() {
    val vault by lazy { SessionVault(this) }
    val repository by lazy { DriveRepository(vault) }
    val transfers by lazy { TransferStore(this) }
}
