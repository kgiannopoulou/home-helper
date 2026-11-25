import { Tabs } from 'expo-router';
import { Text } from 'react-native';
import { AI_ENABLED } from '../../shared/features';
import { HomeButton } from '../../shared/HomeButton';
import { colors } from '../../shared/ui';

const icon = (emoji: string) => () => <Text style={{ fontSize: 20 }}>{emoji}</Text>;

export default function KitchenLayout() {
  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: colors.primary,
        headerStyle: { backgroundColor: colors.bg },
        headerShadowVisible: false,
        headerLeft: () => <HomeButton />,
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Kitchen', tabBarIcon: icon('🏠') }} />
      <Tabs.Screen name="items" options={{ title: 'Inventory', tabBarIcon: icon('📦') }} />
      {/* Receipt reading needs a paid AI key, so the free build adds items by hand in Inventory. */}
      <Tabs.Screen name="scan" options={{ title: 'Scan receipt', tabBarIcon: icon('🧾'), href: AI_ENABLED ? undefined : null }} />
      <Tabs.Screen name="settings" options={{ title: 'Settings', tabBarIcon: icon('⚙️') }} />
    </Tabs>
  );
}
