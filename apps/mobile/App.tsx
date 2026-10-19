import { StatusBar } from 'expo-status-bar';
import { StyleSheet, Text, View } from 'react-native';
import { defaultVerdict, type BuiltinCategory } from '@tresso/core';

const examples: BuiltinCategory[] = ['todo', 'informational', 'advertise'];

export default function App() {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>Every email is something to do, to hand over, or to know.</Text>
      {examples.map((category) => (
        <Text key={category}>
          {category}: {defaultVerdict(category)}
        </Text>
      ))}
      <StatusBar style="auto" />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    padding: 24,
  },
  title: {
    fontSize: 18,
    fontWeight: '600',
    textAlign: 'center',
  },
});
