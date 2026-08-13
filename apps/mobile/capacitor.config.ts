import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'app.selfhandler.mobile',
  appName: 'SelfHandler',
  webDir: '../web/dist',
  android: {
    backgroundColor: '#ece9e2',
  },
  plugins: {
    Keyboard: {
      resize: 'native',
    },
    LocalNotifications: {
      smallIcon: 'ic_stat_selfhandler',
      iconColor: '#3d6b4e',
    },
  },
}

export default config
