plugins { id("com.android.application") }
android {
    namespace = "dev.hari.body"
    compileSdk = 35
    defaultConfig { applicationId = "dev.hari.body"; minSdk = 28; targetSdk = 35; versionCode = 1; versionName = "0.1" }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
}
dependencies { testImplementation("junit:junit:4.13.2") }
